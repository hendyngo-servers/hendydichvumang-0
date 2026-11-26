<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

/**
 * Non-cash 1v1 matchmaking.
 *
 * The queue is concurrency-safe (row locks + SKIP LOCKED), both players must
 * accept, and a result is rated only after both players submit the same score
 * from their own perspective or an Admin resolves a dispute. Wallet tables are
 * intentionally never read or written by this service.
 */
final class MatchmakingService
{
    private const MODES = ['quick', 'ranked'];
    private const ACTIVE_STATUSES = ['awaiting_acceptance', 'active', 'result_pending', 'disputed'];

    public static function joinQueue(string $userId, string $mode = 'quick'): array
    {
        self::assertUserId($userId);
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new RuntimeException('Chế độ ghép trận không hợp lệ.');
        }

        return Database::transaction(function () use ($userId, $mode): array {
            $user = Database::one('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new RuntimeException('Không tìm thấy tài khoản. Hãy gửi /start trước.');
            }
            self::expireInvitations();
            self::ensureStats($userId);

            $active = Database::one(
                "SELECT * FROM matchmaking_matches
                 WHERE (player_one = ? OR player_two = ?)
                   AND status = ANY(?::text[])
                 ORDER BY created_at DESC LIMIT 1",
                [$userId, $userId, self::ACTIVE_STATUSES]
            );
            if ($active !== null) {
                return ['state' => 'in_match', 'match' => self::publicMatch($active, $userId)];
            }

            $waiting = Database::one(
                "SELECT id, mode, created_at FROM matchmaking_queue
                 WHERE user_id = ? AND status = 'waiting'
                 ORDER BY created_at DESC LIMIT 1 FOR UPDATE",
                [$userId]
            );
            if ($waiting !== null && (string) $waiting['mode'] !== $mode) {
                Database::run("UPDATE matchmaking_queue SET status='cancelled',updated_at=NOW() WHERE id=?", [(int) $waiting['id']]);
                $waiting = null;
            }

            $myRating = (int) (Database::value('SELECT rating FROM matchmaking_player_stats WHERE user_id=?', [$userId]) ?? 1000);
            $candidate = Database::one(
                "SELECT q.id, q.user_id, q.mode
                 FROM matchmaking_queue q
                 LEFT JOIN matchmaking_player_stats s ON s.user_id = q.user_id
                 WHERE q.status = 'waiting'
                   AND q.mode = ?
                   AND q.user_id <> ?
                   AND q.created_at > NOW() - INTERVAL '15 minutes'
                   AND NOT EXISTS (
                     SELECT 1 FROM matchmaking_matches m
                     WHERE (m.player_one=q.user_id OR m.player_two=q.user_id)
                       AND m.status = ANY(ARRAY['awaiting_acceptance','active','result_pending','disputed']::text[])
                   )
                 ORDER BY CASE WHEN ? = 'ranked' THEN ABS(COALESCE(s.rating,1000)-?) ELSE 0 END,
                          q.created_at ASC
                 LIMIT 1 FOR UPDATE OF q SKIP LOCKED",
                [$mode, $userId, $mode, $myRating]
            );

            if ($candidate === null) {
                if ($waiting === null) {
                    $waiting = Database::one(
                        "INSERT INTO matchmaking_queue(user_id,mode,status)
                         VALUES(?,?,'waiting') RETURNING id,mode,created_at",
                        [$userId, $mode]
                    );
                } else {
                    Database::run('UPDATE matchmaking_queue SET updated_at=NOW() WHERE id=?', [(int) $waiting['id']]);
                }
                return [
                    'state' => 'waiting',
                    'queueId' => (int) $waiting['id'],
                    'mode' => $mode,
                    'rating' => $myRating,
                    'message' => 'Đã vào hàng chờ. HENDY sẽ thông báo khi tìm được đối thủ.',
                ];
            }

            if ($waiting === null) {
                $waiting = Database::one(
                    "INSERT INTO matchmaking_queue(user_id,mode,status)
                     VALUES(?,?,'matched') RETURNING id",
                    [$userId, $mode]
                );
            } else {
                Database::run("UPDATE matchmaking_queue SET status='matched',updated_at=NOW() WHERE id=?", [(int) $waiting['id']]);
            }

            $match = Database::one(
                "INSERT INTO matchmaking_matches(player_one,player_two,mode,status,expires_at)
                 VALUES(?,?,?,'awaiting_acceptance',NOW() + INTERVAL '3 minutes')
                 RETURNING id,player_one,player_two,mode,status,created_at,expires_at,
                           player_one_accepted_at,player_two_accepted_at",
                [(string) $candidate['user_id'], $userId, $mode]
            );
            if ($match === null) throw new RuntimeException('Không thể tạo trận ghép đôi.');

            Database::run(
                "UPDATE matchmaking_queue
                 SET status='matched',matched_user_id=?,match_id=?,updated_at=NOW()
                 WHERE id=?",
                [$userId, (int) $match['id'], (int) $candidate['id']]
            );
            Database::run(
                "UPDATE matchmaking_queue
                 SET status='matched',matched_user_id=?,match_id=?,updated_at=NOW()
                 WHERE id=?",
                [(string) $candidate['user_id'], (int) $match['id'], (int) $waiting['id']]
            );

            return ['state' => 'matched', 'match' => self::publicMatch($match, $userId)];
        });
    }

    public static function cancelQueue(string $userId): bool
    {
        self::assertUserId($userId);
        $result = Database::run(
            "UPDATE matchmaking_queue SET status='cancelled',updated_at=NOW()
             WHERE user_id=? AND status='waiting' RETURNING id",
            [$userId]
        );
        return $result->rowCount() > 0;
    }

    public static function acceptMatch(string $userId, int $matchId): array
    {
        self::assertUserId($userId);
        if ($matchId < 1) throw new RuntimeException('Mã trận đấu không hợp lệ.');

        return Database::transaction(function () use ($userId, $matchId): array {
            $match = Database::one('SELECT * FROM matchmaking_matches WHERE id=? FOR UPDATE', [$matchId]);
            if ($match === null) throw new RuntimeException('Không tìm thấy trận đấu.');
            self::assertParticipant($match, $userId);
            if ((string) $match['status'] !== 'awaiting_acceptance') {
                return ['state' => (string) $match['status'], 'match' => self::publicMatch($match, $userId)];
            }
            if (empty($match['expires_at']) || strtotime((string) $match['expires_at']) <= time()) {
                self::expireOneMatch($matchId, $match);
                return ['state' => 'expired', 'match' => self::publicMatch($match, $userId)];
            }

            $field = $userId === (string) $match['player_one'] ? 'player_one_accepted_at' : 'player_two_accepted_at';
            Database::run("UPDATE matchmaking_matches SET {$field}=COALESCE({$field},NOW()) WHERE id=?", [$matchId]);
            $updated = Database::one('SELECT * FROM matchmaking_matches WHERE id=?', [$matchId]);
            $bothAccepted = !empty($updated['player_one_accepted_at']) && !empty($updated['player_two_accepted_at']);
            if ($bothAccepted) {
                Database::run(
                    "UPDATE matchmaking_matches SET status='active',started_at=COALESCE(started_at,NOW()),expires_at=NULL
                     WHERE id=? AND status='awaiting_acceptance'",
                    [$matchId]
                );
                Database::run("UPDATE matchmaking_queue SET status='active',updated_at=NOW() WHERE match_id=?", [$matchId]);
                $updated = Database::one('SELECT * FROM matchmaking_matches WHERE id=?', [$matchId]);
                return ['state' => 'active', 'match' => self::publicMatch($updated ?? $match, $userId)];
            }

            return ['state' => 'waiting_for_opponent', 'match' => self::publicMatch($updated ?? $match, $userId)];
        });
    }

    public static function declineMatch(string $userId, int $matchId): array
    {
        self::assertUserId($userId);
        return Database::transaction(function () use ($userId, $matchId): array {
            $match = Database::one('SELECT * FROM matchmaking_matches WHERE id=? FOR UPDATE', [$matchId]);
            if ($match === null) throw new RuntimeException('Không tìm thấy trận đấu.');
            self::assertParticipant($match, $userId);
            if ((string) $match['status'] !== 'awaiting_acceptance') {
                throw new RuntimeException('Trận đấu không còn chờ xác nhận.');
            }
            $opponent = $userId === (string) $match['player_one'] ? (string) $match['player_two'] : (string) $match['player_one'];
            Database::run(
                "UPDATE matchmaking_matches SET status='cancelled',cancelled_by=?,cancel_reason='declined',finished_at=NOW(),expires_at=NULL WHERE id=?",
                [$userId, $matchId]
            );
            Database::run(
                "UPDATE matchmaking_queue SET status='cancelled',updated_at=NOW()
                 WHERE match_id=? AND user_id=?",
                [$matchId, $userId]
            );
            Database::run(
                "UPDATE matchmaking_queue SET status='waiting',matched_user_id=NULL,match_id=NULL,updated_at=NOW()
                 WHERE match_id=? AND user_id=?",
                [$matchId, $opponent]
            );
            return ['state' => 'cancelled', 'opponentId' => $opponent, 'matchId' => $matchId];
        });
    }

    public static function status(string $userId): array
    {
        self::assertUserId($userId);
        self::expireInvitations();
        self::ensureStats($userId);
        $stats = Database::one('SELECT rating,points,matches_played,wins,losses,draws FROM matchmaking_player_stats WHERE user_id=?', [$userId]) ?? [];
        $queue = Database::one(
            "SELECT id,mode,status,created_at FROM matchmaking_queue
             WHERE user_id=? AND status IN ('waiting','matched','active')
             ORDER BY created_at DESC LIMIT 1",
            [$userId]
        );
        $match = Database::one(
            "SELECT m.*, r.score_one,r.score_two,r.status AS result_status,r.reported_by
             FROM matchmaking_matches m
             LEFT JOIN matchmaking_results r ON r.match_id=m.id
             WHERE (m.player_one=? OR m.player_two=?)
               AND m.status=ANY(?::text[])
             ORDER BY m.created_at DESC LIMIT 1",
            [$userId,$userId,self::ACTIVE_STATUSES]
        );
        return ['stats' => $stats, 'queue' => $queue, 'match' => $match ? self::publicMatch($match,$userId) : null];
    }

    public static function history(string $userId, int $limit = 20): array
    {
        self::assertUserId($userId);
        $limit = max(1,min(50,$limit));
        return Database::all(
            "SELECT m.id,m.player_one,m.player_two,m.mode,m.status,m.created_at,m.finished_at,
                    r.score_one,r.score_two,r.status AS result_status,
                    CASE WHEN m.player_one=? THEN 'one' ELSE 'two' END AS perspective
             FROM matchmaking_matches m
             LEFT JOIN matchmaking_results r ON r.match_id=m.id
             WHERE m.player_one=? OR m.player_two=?
             ORDER BY m.created_at DESC LIMIT ?",
            [$userId,$userId,$userId,$limit]
        );
    }

    public static function leaderboard(int $limit = 20): array
    {
        $limit = max(1,min(50,$limit));
        return Database::all(
            "SELECT s.user_id,u.name,u.username,s.rating,s.points,s.matches_played,s.wins,s.losses,s.draws
             FROM matchmaking_player_stats s
             JOIN users u ON u.user_id=s.user_id
             WHERE s.matches_played > 0
             ORDER BY s.rating DESC,s.points DESC,s.matches_played ASC
             LIMIT ?",
            [$limit]
        );
    }

    public static function submitResult(string $userId, int $matchId, int $scoreFor, int $scoreAgainst): array
    {
        self::assertUserId($userId);
        self::assertScore($scoreFor);
        self::assertScore($scoreAgainst);

        return Database::transaction(function () use ($userId,$matchId,$scoreFor,$scoreAgainst): array {
            $match = Database::one('SELECT * FROM matchmaking_matches WHERE id=? FOR UPDATE',[$matchId]);
            if ($match === null) throw new RuntimeException('Không tìm thấy trận đấu.');
            self::assertParticipant($match,$userId);
            if (!in_array((string) $match['status'],['active','result_pending'],true)) {
                throw new RuntimeException('Trận đấu chưa bắt đầu hoặc không còn nhận kết quả.');
            }
            $isOne = $userId === (string) $match['player_one'];
            $scoreOne = $isOne ? $scoreFor : $scoreAgainst;
            $scoreTwo = $isOne ? $scoreAgainst : $scoreFor;
            $existing = Database::one('SELECT * FROM matchmaking_results WHERE match_id=? FOR UPDATE',[$matchId]);

            if ($existing === null) {
                Database::run(
                    "INSERT INTO matchmaking_results(match_id,reported_by,score_one,score_two,status)
                     VALUES(?,?,?,?,'pending')",
                    [$matchId,$userId,$scoreOne,$scoreTwo]
                );
                Database::run("UPDATE matchmaking_matches SET status='result_pending' WHERE id=?",[$matchId]);
                return ['state'=>'pending','opponentId'=>$isOne?(string)$match['player_two']:(string)$match['player_one'],'scoreOne'=>$scoreOne,'scoreTwo'=>$scoreTwo];
            }
            if ((string) $existing['status'] === 'confirmed') {
                throw new RuntimeException('Kết quả trận đấu đã được xác nhận.');
            }
            if ((string) $existing['status'] === 'disputed') {
                throw new RuntimeException('Kết quả đang được Admin xem xét.');
            }
            if ((string) $existing['reported_by'] === $userId) {
                Database::run(
                    'UPDATE matchmaking_results SET score_one=?,score_two=?,updated_at=NOW() WHERE match_id=?',
                    [$scoreOne,$scoreTwo,$matchId]
                );
                return ['state'=>'pending','opponentId'=>$isOne?(string)$match['player_two']:(string)$match['player_one'],'scoreOne'=>$scoreOne,'scoreTwo'=>$scoreTwo];
            }
            if ((int)$existing['score_one'] !== $scoreOne || (int)$existing['score_two'] !== $scoreTwo) {
                Database::run("UPDATE matchmaking_results SET status='disputed',updated_at=NOW() WHERE match_id=?",[$matchId]);
                Database::run("UPDATE matchmaking_matches SET status='disputed' WHERE id=?",[$matchId]);
                return ['state'=>'disputed','opponentId'=>(string)$existing['reported_by'],'scoreOne'=>$scoreOne,'scoreTwo'=>$scoreTwo];
            }

            return self::finalizeResult($match,$existing,$userId,$scoreOne,$scoreTwo,false,'');
        });
    }

    public static function resolveByAdmin(int $matchId, int $scoreOne, int $scoreTwo, string $adminId, string $note): array
    {
        self::assertScore($scoreOne);
        self::assertScore($scoreTwo);
        $note = trim($note);
        if (mb_strlen($note,'UTF-8') > 500) throw new RuntimeException('Ghi chú Admin tối đa 500 ký tự.');

        return Database::transaction(function () use ($matchId,$scoreOne,$scoreTwo,$adminId,$note): array {
            $match = Database::one('SELECT * FROM matchmaking_matches WHERE id=? FOR UPDATE',[$matchId]);
            if ($match === null) throw new RuntimeException('Không tìm thấy trận đấu.');
            if (!in_array((string)$match['status'],['active','result_pending','disputed'],true)) {
                throw new RuntimeException('Trận đấu không ở trạng thái có thể duyệt kết quả.');
            }
            $existing = Database::one('SELECT * FROM matchmaking_results WHERE match_id=? FOR UPDATE',[$matchId]);
            if ($existing !== null && (string)$existing['status'] === 'confirmed') {
                throw new RuntimeException('Kết quả đã được ghi nhận; không thể cộng điểm lần thứ hai.');
            }
            if ($existing === null) {
                Database::run(
                    "INSERT INTO matchmaking_results(match_id,reported_by,score_one,score_two,status)
                     VALUES(?,?,?,?,'pending')",
                    [$matchId,(string)$match['player_one'],$scoreOne,$scoreTwo]
                );
                $existing = Database::one('SELECT * FROM matchmaking_results WHERE match_id=? FOR UPDATE',[$matchId]);
            } else {
                Database::run(
                    "UPDATE matchmaking_results SET score_one=?,score_two=?,status='pending',updated_at=NOW()
                     WHERE match_id=?",
                    [$scoreOne,$scoreTwo,$matchId]
                );
                $existing = Database::one('SELECT * FROM matchmaking_results WHERE match_id=? FOR UPDATE',[$matchId]);
            }
            return self::finalizeResult($match,$existing ?? [],$adminId,$scoreOne,$scoreTwo,true,$note);
        });
    }

    public static function adminMatches(string $status = '', int $limit = 100): array
    {
        $status = trim($status);
        $allowed = ['awaiting_acceptance','active','result_pending','disputed','completed','cancelled','expired'];
        if ($status !== '' && !in_array($status,$allowed,true)) throw new RuntimeException('Trạng thái trận đấu không hợp lệ.');
        $limit = max(1,min(200,$limit));
        return Database::all(
            "SELECT m.id,m.player_one,m.player_two,m.mode,m.status,m.created_at,m.started_at,m.finished_at,m.expires_at,
                    m.player_one_accepted_at,m.player_two_accepted_at,
                    u1.name AS player_one_name,u1.username AS player_one_username,
                    u2.name AS player_two_name,u2.username AS player_two_username,
                    r.score_one,r.score_two,r.status AS result_status,r.reported_by,r.resolution_note
             FROM matchmaking_matches m
             JOIN users u1 ON u1.user_id=m.player_one
             JOIN users u2 ON u2.user_id=m.player_two
             LEFT JOIN matchmaking_results r ON r.match_id=m.id
             WHERE (?='' OR m.status=?)
             ORDER BY m.created_at DESC LIMIT ?",
            [$status,$status,$limit]
        );
    }

    private static function finalizeResult(array $match, array $existing, string $confirmedBy, int $scoreOne, int $scoreTwo, bool $adminResolved, string $note): array
    {
        $playerOne = (string) $match['player_one'];
        $playerTwo = (string) $match['player_two'];
        self::ensureStats($playerOne);
        self::ensureStats($playerTwo);
        $stats = Database::all(
            'SELECT user_id,rating FROM matchmaking_player_stats WHERE user_id IN (?,?) ORDER BY user_id FOR UPDATE',
            [$playerOne,$playerTwo]
        );
        $ratings = [];
        foreach ($stats as $row) $ratings[(string)$row['user_id']] = (int)$row['rating'];
        $r1 = $ratings[$playerOne] ?? 1000;
        $r2 = $ratings[$playerTwo] ?? 1000;

        $deltaOne = 0;
        $deltaTwo = 0;
        if ((string)$match['mode'] === 'ranked') {
            $expectedOne = 1.0 / (1.0 + pow(10.0, ($r2 - $r1) / 400.0));
            $actualOne = $scoreOne > $scoreTwo ? 1.0 : ($scoreOne < $scoreTwo ? 0.0 : 0.5);
            $deltaOne = (int) round(32.0 * ($actualOne - $expectedOne));
            $deltaTwo = -$deltaOne;
        }

        if ($scoreOne === $scoreTwo) {
            $pointsOne = $pointsTwo = 1;
            $outcomeOne = 'draw';
            $outcomeTwo = 'draw';
        } elseif ($scoreOne > $scoreTwo) {
            $pointsOne = (string)$match['mode'] === 'ranked' ? 3 : 1;
            $pointsTwo = 0;
            $outcomeOne = 'win';
            $outcomeTwo = 'loss';
        } else {
            $pointsOne = 0;
            $pointsTwo = (string)$match['mode'] === 'ranked' ? 3 : 1;
            $outcomeOne = 'loss';
            $outcomeTwo = 'win';
        }

        foreach ([[$playerOne,$deltaOne,$pointsOne,$outcomeOne],[$playerTwo,$deltaTwo,$pointsTwo,$outcomeTwo]] as [$id,$delta,$points,$outcome]) {
            Database::run(
                "UPDATE matchmaking_player_stats
                 SET rating=GREATEST(0,rating+?),points=points+?,matches_played=matches_played+1,
                     wins=wins+?,losses=losses+?,draws=draws+?,updated_at=NOW()
                 WHERE user_id=?",
                [$delta,$points,$outcome==='win'?1:0,$outcome==='loss'?1:0,$outcome==='draw'?1:0,$id]
            );
        }

        Database::run(
            "UPDATE matchmaking_results
             SET status='confirmed',confirmed_by=?,confirmed_at=NOW(),updated_at=NOW(),
                 rating_delta_one=?,rating_delta_two=?,points_awarded_one=?,points_awarded_two=?,
                 resolved_by=?,resolution_note=?
             WHERE match_id=? AND status<>'confirmed'",
            [$confirmedBy,$deltaOne,$deltaTwo,$pointsOne,$pointsTwo,$adminResolved?$confirmedBy:null,$adminResolved?$note:'',(int)$match['id']]
        );
        Database::run(
            "UPDATE matchmaking_matches SET status='completed',finished_at=NOW(),expires_at=NULL WHERE id=?",
            [(int)$match['id']]
        );
        Database::run(
            "UPDATE matchmaking_queue SET status='completed',updated_at=NOW() WHERE match_id=?",
            [(int)$match['id']]
        );

        return [
            'state'=>'confirmed',
            'matchId'=>(int)$match['id'],
            'playerOne'=>$playerOne,
            'playerTwo'=>$playerTwo,
            'scoreOne'=>$scoreOne,
            'scoreTwo'=>$scoreTwo,
            'ratingDeltaOne'=>$deltaOne,
            'ratingDeltaTwo'=>$deltaTwo,
            'pointsAwardedOne'=>$pointsOne,
            'pointsAwardedTwo'=>$pointsTwo,
            'adminResolved'=>$adminResolved,
        ];
    }

    private static function expireInvitations(): void
    {
        $expired = Database::all(
            "UPDATE matchmaking_matches SET status='expired',finished_at=NOW(),expires_at=NULL,
                    cancel_reason='acceptance_timeout'
             WHERE status='awaiting_acceptance' AND expires_at <= NOW()
             RETURNING id,player_one_accepted_at,player_two_accepted_at"
        );
        foreach ($expired as $match) {
            Database::run("UPDATE matchmaking_queue SET status='expired',updated_at=NOW() WHERE match_id=?",[(int)$match['id']]);
        }
    }

    private static function expireOneMatch(int $matchId, array &$match): void
    {
        Database::run(
            "UPDATE matchmaking_matches SET status='expired',finished_at=NOW(),expires_at=NULL,cancel_reason='acceptance_timeout'
             WHERE id=? AND status='awaiting_acceptance'",
            [$matchId]
        );
        Database::run("UPDATE matchmaking_queue SET status='expired',updated_at=NOW() WHERE match_id=?",[$matchId]);
        $match['status']='expired';
    }

    private static function ensureStats(string $userId): void
    {
        Database::run(
            'INSERT INTO matchmaking_player_stats(user_id) VALUES(?) ON CONFLICT(user_id) DO NOTHING',
            [$userId]
        );
    }

    private static function publicMatch(array $match, string $userId): array
    {
        $isOne = $userId === (string)$match['player_one'];
        return [
            'id'=>(int)$match['id'],
            'playerOne'=>(string)$match['player_one'],
            'playerTwo'=>(string)$match['player_two'],
            'opponentId'=>$isOne?(string)$match['player_two']:(string)$match['player_one'],
            'isPlayerOne'=>$isOne,
            'mode'=>(string)$match['mode'],
            'status'=>(string)$match['status'],
            'playerOneAccepted'=>!empty($match['player_one_accepted_at']),
            'playerTwoAccepted'=>!empty($match['player_two_accepted_at']),
            'expiresAt'=>$match['expires_at']??null,
            'createdAt'=>$match['created_at']??null,
            'startedAt'=>$match['started_at']??null,
            'finishedAt'=>$match['finished_at']??null,
            'scoreOne'=>isset($match['score_one'])?(int)$match['score_one']:null,
            'scoreTwo'=>isset($match['score_two'])?(int)$match['score_two']:null,
            'resultStatus'=>$match['result_status']??null,
        ];
    }

    private static function assertParticipant(array $match, string $userId): void
    {
        if ($userId !== (string)$match['player_one'] && $userId !== (string)$match['player_two']) {
            throw new RuntimeException('Bạn không phải người tham gia trận đấu này.');
        }
    }

    private static function assertUserId(string $userId): void
    {
        if (!preg_match('/\\A[0-9]{5,20}\\z/', $userId)) {
            throw new RuntimeException('Telegram ID không hợp lệ.');
        }
    }

    private static function assertScore(int $score): void
    {
        if ($score < 0 || $score > 999) throw new RuntimeException('Điểm trận đấu phải từ 0 đến 999.');
    }
}
