#!/usr/bin/env bash
set -euo pipefail
if [[ "$EUID" -ne 0 ]]; then
  echo "Chạy bằng sudo: sudo bash deploy/ubuntu/install-docker.sh" >&2
  exit 1
fi
if [[ ! -r /etc/os-release ]]; then
  echo "Không đọc được /etc/os-release." >&2
  exit 1
fi
# shellcheck disable=SC1091
. /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "26.04" ]]; then
  echo "Chỉ hỗ trợ Ubuntu 26.04 LTS; phát hiện ${PRETTY_NAME:-unknown}." >&2
  exit 1
fi
arch="$(dpkg --print-architecture)"
if [[ "$arch" != "amd64" ]]; then
  echo "Yêu cầu amd64; kiến trúc hiện tại: $arch." >&2
  exit 1
fi
conflicts=(docker.io docker-doc docker-compose docker-compose-v2 docker-buildx podman-docker containerd runc)
installed=()
for package in "${conflicts[@]}"; do
  if dpkg-query -W -f='${db:Status-Status}' "$package" 2>/dev/null | grep -qx installed; then
    installed+=("$package")
  fi
done
if (("${#installed[@]}" > 0)); then
  printf 'Phát hiện gói xung đột: %s\n' "${installed[*]}" >&2
  echo "Không tự gỡ gói để tránh ảnh hưởng máy chủ. Hãy rà soát/gỡ thủ công rồi chạy lại." >&2
  exit 1
fi
suite="${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}"
if [[ -z "$suite" ]]; then
  echo "Không xác định được Ubuntu codename." >&2
  exit 1
fi
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $suite
Components: stable
Architectures: $arch
Signed-By: /etc/apt/keyrings/docker.asc
EOF
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker
docker --version
docker compose version
echo "Đã cài Docker Engine và Compose Plugin trên Ubuntu 26.04 amd64."
