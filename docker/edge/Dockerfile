FROM alpine:3.20
RUN apk add --no-cache sslh
EXPOSE 8080
ENTRYPOINT ["sslh", "-f", "-u", "root", "--listen", "0.0.0.0:8080", "--tls", "web:443", "--http", "web:80"]
