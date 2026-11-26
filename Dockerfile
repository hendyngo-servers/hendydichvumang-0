FROM nginx:1.27-alpine
COPY website/public/ /usr/share/nginx/html/
COPY deploy/nginx-website.conf /etc/nginx/conf.d/default.conf
EXPOSE 10000
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD wget -q -O /dev/null http://127.0.0.1:10000/healthz.html || exit 1
