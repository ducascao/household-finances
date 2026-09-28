#!/bin/sh
# Backup diário do Postgres: pg_dump em formato custom (já compactado), retenção de N dias.
#   backup.sh        -> roda para sempre, fazendo um backup por dia às BACKUP_AT (HH:MM)
#   backup.sh now    -> faz um backup agora e sai
set -eu

BACKUP_DIR=/backups
BACKUP_AT="${BACKUP_AT:-03:00}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

run_backup() {
    file="$BACKUP_DIR/${PGDATABASE}_$(date +%Y-%m-%d_%H%M).dump"
    tmp="$file.partial"

    pg_dump --format=custom --compress=9 --no-owner --file="$tmp"
    mv "$tmp" "$file"
    echo "$(date '+%F %T') backup criado: $file ($(du -h "$file" | cut -f1))"

    find "$BACKUP_DIR" -name '*.dump' -type f -mtime "+$RETENTION_DAYS" -print -delete \
        | sed "s/^/$(date '+%F %T') removido (mais de $RETENTION_DAYS dias): /"
}

if [ "${1:-}" = "now" ]; then
    run_backup
    exit 0
fi

echo "$(date '+%F %T') agendador de backup iniciado: diariamente às $BACKUP_AT, retenção de $RETENTION_DAYS dias"

while true; do
    now=$(date +%s)
    next=$(date -d "today $BACKUP_AT" +%s)
    [ "$next" -le "$now" ] && next=$(date -d "tomorrow $BACKUP_AT" +%s)

    sleep $((next - now))
    run_backup || echo "$(date '+%F %T') ERRO no backup" >&2
done
