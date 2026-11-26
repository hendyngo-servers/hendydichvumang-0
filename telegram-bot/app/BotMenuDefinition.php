<?php
declare(strict_types=1);

namespace Hendy;

/**
 * Main member menu focused on active customer journeys.
 * Four requested actions (matchmaking, orders, history and home) remain
 * available through their existing commands/submenus but are not top-level buttons.
 */
final class BotMenuDefinition
{
    public const ITEMS = [
        ['action' => 'live', 'label' => '🎮 Mini Game Live', 'description' => 'Chọn nhà cái và đặt gói Mini Game Live.', 'style' => 'primary'],
        ['action' => 'tools', 'label' => '🧰 Thuê Tool', 'description' => 'Xem danh mục công cụ và dịch vụ cho thuê.', 'style' => 'primary'],
        ['action' => 'codes', 'label' => '📡 Code / mã dịch vụ', 'description' => 'Mở kho Code Telegram và các mã dịch vụ.', 'style' => 'primary'],
        ['action' => 'otp', 'label' => '📱 OTP SMS', 'description' => 'Chọn dịch vụ OTP SMS đang được mở bán.', 'style' => 'primary'],
        ['action' => 'proxy', 'label' => '🌐 Proxy', 'description' => 'Xem và đặt mua gói Proxy khả dụng.', 'style' => 'primary'],
        ['action' => 'vip', 'label' => '👑 Sản phẩm VIP', 'description' => 'Xem kho sản phẩm VIP và quyền lợi thành viên.', 'style' => 'success'],
        ['action' => 'customer', 'label' => '💠 Trung tâm khách hàng HENDY', 'description' => 'Hồ sơ, ví, hỗ trợ và liên kết tài khoản HENDY.', 'style' => 'success'],
        ['action' => 'vietsub', 'label' => '🎬 Vietsub', 'description' => 'Gửi liên kết video để tạo tác vụ phụ đề.', 'style' => 'primary'],
        ['action' => 'gift', 'label' => '🎁 Giftcode', 'description' => 'Nhập mã Giftcode để nhận ưu đãi hợp lệ.', 'style' => 'success'],
        ['action' => 'ref', 'label' => '🔗 Giới thiệu', 'description' => 'Lấy liên kết giới thiệu và xem thống kê REF.', 'style' => 'primary'],
        ['action' => 'ai-features', 'label' => '🤖 Tính năng AI', 'description' => 'Mở các công cụ AI đang được hệ thống cho phép.', 'style' => 'primary'],
    ];

    /** Alternating 2-1 rows; Admin is appended separately for Admin accounts. */
    public const LAYOUT = [
        ['live', 'tools'],
        ['codes'],
        ['otp', 'proxy'],
        ['vip'],
        ['customer'],
        ['vietsub', 'gift'],
        ['ref', 'ai-features'],
    ];

    // Child/legacy actions remain registered to preserve old callbacks and commands.
    public const ACTIONS = [
        'live', 'tools', 'codes', 'otp', 'proxy', 'vip', 'customer',
        'matchmaking', 'vietsub', 'gift', 'ref', 'orders', 'history', 'home',
        'ai-features', 'music-ai', 'image-ai', 'ai-cover', 'group', 'news',
        'admin', 'viptools', 'miniapp', 'jobs', 'abcvip', 'support',
    ];
}
