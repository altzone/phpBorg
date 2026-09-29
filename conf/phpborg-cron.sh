#!/bin/bash
#
# Lancement du "full" sous verrou, avec un echec bavard.
#
# Pourquoi ce script : la ligne de cron d'origine etait
#
#   0 22 * * * flock -n /run/lock/phpborg.lock phpborg full >> /var/log/phpborg.log 2>&1
#
# "flock -n" sort silencieusement en code 1 quand le verrou est deja tenu.
# Le 28/09/2026 une tache bloquee a retenu le verrou 12 h : sans ce script,
# le full du 29/09 a 22h aurait echoue sans une ligne de journal ni un mail,
# et la meme chose chaque nuit suivante.
#
# Usage : phpborg-cron.sh [full]
#
set -u

# Surchargeable pour les tests, sans toucher au verrou de production.
LOCK="${PHPBORG_LOCK:-/run/lock/phpborg.lock}"
LOG="${PHPBORG_LOG:-/var/log/phpborg.log}"
BIN=/usr/bin/phpborg
CMD="${1:-full}"

# LC_ALL=C : le journal PHP ecrit "29-Sep-2026", pas "29-sept.-2026".
horodate() { LC_ALL=C date '+[%d-%b-%Y %H:%M:%S]'; }

exec 9>"$LOCK" || {
    echo "$(horodate) : [ERROR] [CRON] - Impossible d'ouvrir $LOCK" >> "$LOG"
    exit 1
}

if ! flock -n 9; then
    # Qui tient le verrou ? On nomme le processus, pas seulement le fait.
    detenteur=$(pgrep -a -f "phpborg $CMD" 2>/dev/null | head -1)
    [ -z "$detenteur" ] && detenteur=$(pgrep -a -f 'phpborg\.php' 2>/dev/null | head -1)
    [ -z "$detenteur" ] && detenteur="inconnu (verrou tenu sans processus phpborg identifie)"

    depuis=$(stat -c %y "$LOCK" 2>/dev/null | cut -d. -f1)
    msg="Le full de $(date '+%H:%M') n'a PAS ete lance : $LOCK est deja tenu."
    msg="$msg Detenteur : $detenteur. Verrou date du : ${depuis:-inconnu}."
    msg="$msg Une execution precedente n'a pas rendu la main."

    echo "$(horodate) : [ERROR] [CRON] - $msg" >> "$LOG"
    logger -t phpborg "$msg"
    "$BIN" alert "$msg" >> "$LOG" 2>&1
    exit 1
fi

# Le pid dans le fichier permet de reconnaitre un verrou perime.
echo $$ >&9

echo "$(horodate) : [INFO] [CRON] - Lancement de 'phpborg $CMD'" >> "$LOG"
exec "$BIN" "$CMD" >> "$LOG" 2>&1
