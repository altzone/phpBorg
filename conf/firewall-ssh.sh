#!/bin/bash
#
# Protection SSH du serveur de sauvegarde (chaine INPUT uniquement).
#
# Pourquoi INPUT et pas nftables/ufw : Docker reecrit en permanence les tables
# nat et FORWARD. /etc/nftables.conf commence par "flush ruleset" et effacerait
# les regles Docker (tous les DNAT des conteneurs). La chaine INPUT n'est pas
# touchee par Docker : on y intervient sans risque pour les conteneurs.
#
# Principe :
#   - les reseaux et IP de confiance passent sans limite
#   - les autres sont limites a N nouvelles connexions par fenetre de temps
#     (le brute-force meurt, un acces de secours reste possible)
#
# Usage :
#   ./firewall-ssh.sh check     affiche ce qui serait applique, ne touche a rien
#   ./firewall-ssh.sh apply     applique AVEC rollback automatique (voir DELAI)
#   ./firewall-ssh.sh confirm   annule le rollback : les regles deviennent definitives
#   ./firewall-ssh.sh revert    retire immediatement les regles
#   ./firewall-ssh.sh status    etat courant
#   ./firewall-ssh.sh boot      applique sans rollback (service systemd)
#
set -u

CHAIN="SSH-GUARD"
PORT=22
# Nombre de nouvelles connexions autorisees par IP non listee...
HITCOUNT=5
# ...sur cette fenetre glissante, en secondes
SECONDS_WINDOW=300
# Delai avant rollback automatique apres "apply", en secondes
DELAI=600

STATE_DIR=/run/phpborg-fw
BACKUP="$STATE_DIR/iptables-before.rules"
ROLLBACK_PID="$STATE_DIR/rollback.pid"

# --- Reseaux et IP toujours autorises -------------------------------------
# Reseau interne (postes d'administration + 34 serveurs sauvegardes)
TRUSTED_NETS=(
    "10.0.0.0/8"
    "172.16.0.0/12"
    "192.168.0.0/16"
    "127.0.0.0/8"
)

# IP publiques des serveurs sauvegardes : ils se reconnectent vers ce serveur
# pour deposer leurs archives (ssh://<srv>@91.200.205.105). Sans ces entrees,
# toutes leurs sauvegardes tombent.
TRUSTED_IPS=(
    "5.135.150.235"      # cartobio-preprod
    "46.105.222.55"      # ab-notif-bdd1
    "51.38.62.232"       # ab-pg2
    "51.38.62.235"       # ab-nodejs2
    "51.38.62.236"       # ab-front
    "51.68.35.104"       # ab-web
    "51.77.133.58"       # ab-preprod
    "91.134.137.224"     # cartobio
    "91.200.205.120"     # cinrel-srv1
    "137.74.114.106"     # ab-bastion
    "141.95.158.77"      # ab-web-preprod
    "146.59.152.104"     # ab-postgis
    "162.19.57.177"      # bdd-cartobio
    "164.132.247.141"    # ab-notif-apps1
    "209.208.63.151"     # ns0-net1c
)

# IP d'administration supplementaires (postes distants, VPN externe...)
# Le bastion est le point d'entree des administrateurs : il doit rester
# joignable en toutes circonstances, y compris par son IP publique.
ADMIN_IPS=(
    "91.200.204.13"      # bastion (IP publique) - 10.10.204.130 en interne
)

log() { printf '%s\n' "$*"; }

build_rules() {
    log "# Chaine dediee, inseree en tete de INPUT"
    log "iptables -N $CHAIN"
    log "iptables -I INPUT 1 -p tcp --dport $PORT -m conntrack --ctstate NEW -j $CHAIN"
    log ""
    log "# Connexions deja etablies : jamais filtrees"
    log "iptables -A $CHAIN -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN"
    log ""
    log "# Reseaux de confiance"
    for n in "${TRUSTED_NETS[@]}"; do
        log "iptables -A $CHAIN -s $n -j RETURN"
    done
    log ""
    log "# Serveurs sauvegardes (IP publiques)"
    for ip in "${TRUSTED_IPS[@]}"; do
        log "iptables -A $CHAIN -s $ip -j RETURN"
    done
    if [ ${#ADMIN_IPS[@]} -gt 0 ]; then
        log ""
        log "# Postes d'administration"
        for ip in "${ADMIN_IPS[@]}"; do
            log "iptables -A $CHAIN -s $ip -j RETURN"
        done
    fi
    log ""
    log "# Reste du monde : $HITCOUNT nouvelles connexions max par ${SECONDS_WINDOW}s"
    log "iptables -A $CHAIN -m recent --name sshprobe --set"
    log "iptables -A $CHAIN -m recent --name sshprobe --update --seconds $SECONDS_WINDOW --hitcount $HITCOUNT -j LOG --log-prefix '[SSH-GUARD] ' --log-level 4 -m limit --limit 2/min"
    log "iptables -A $CHAIN -m recent --name sshprobe --update --seconds $SECONDS_WINDOW --hitcount $HITCOUNT -j DROP"
    log "iptables -A $CHAIN -j RETURN"
}

apply_rules() {
    iptables -N "$CHAIN" 2>/dev/null
    iptables -F "$CHAIN"

    iptables -A "$CHAIN" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    for n in "${TRUSTED_NETS[@]}";  do iptables -A "$CHAIN" -s "$n"  -j RETURN; done
    for ip in "${TRUSTED_IPS[@]}";  do iptables -A "$CHAIN" -s "$ip" -j RETURN; done
    for ip in "${ADMIN_IPS[@]:-}";  do [ -n "$ip" ] && iptables -A "$CHAIN" -s "$ip" -j RETURN; done

    iptables -A "$CHAIN" -m recent --name sshprobe --set
    iptables -A "$CHAIN" -m recent --name sshprobe --update \
        --seconds "$SECONDS_WINDOW" --hitcount "$HITCOUNT" \
        -m limit --limit 2/min -j LOG --log-prefix "[SSH-GUARD] " --log-level 4
    iptables -A "$CHAIN" -m recent --name sshprobe --update \
        --seconds "$SECONDS_WINDOW" --hitcount "$HITCOUNT" -j DROP
    iptables -A "$CHAIN" -j RETURN

    # Branchement en tete de INPUT, sans doublon
    iptables -C INPUT -p tcp --dport "$PORT" -m conntrack --ctstate NEW -j "$CHAIN" 2>/dev/null \
        || iptables -I INPUT 1 -p tcp --dport "$PORT" -m conntrack --ctstate NEW -j "$CHAIN"
}

revert_rules() {
    iptables -D INPUT -p tcp --dport "$PORT" -m conntrack --ctstate NEW -j "$CHAIN" 2>/dev/null
    iptables -F "$CHAIN" 2>/dev/null
    iptables -X "$CHAIN" 2>/dev/null
}

case "${1:-check}" in
check)
    echo "=== Regles qui seraient appliquees ==="
    build_rules
    echo
    echo "=== Verification des prerequis ==="
    for m in conntrack recent limit; do
        if iptables -m "$m" --help >/dev/null 2>&1; then
            echo "  module $m : disponible"
        else
            echo "  module $m : MANQUANT -> le script ne fonctionnera pas"
        fi
    done
    echo "  chaine $CHAIN existante : $(iptables -S "$CHAIN" >/dev/null 2>&1 && echo oui || echo non)"
    ;;

boot)
    # Applique les regles sans filet de rollback : au demarrage il n'y a
    # personne pour confirmer. En cas d'echec, on sort en erreur pour que
    # systemctl status le signale, mais le port 22 reste simplement ouvert
    # comme avant : un echec ne peut pas couper l'acces a la machine.
    apply_rules || { echo "Application des regles echouee" >&2; exit 1; }
    if iptables -C INPUT -p tcp --dport "$PORT" -m conntrack --ctstate NEW -j "$CHAIN" 2>/dev/null; then
        echo "Regles SSH appliquees"
        logger -t phpborg-fw "regles SSH appliquees au demarrage"
    else
        echo "Regles absentes de INPUT apres application" >&2
        exit 1
    fi
    ;;

apply)
    mkdir -p "$STATE_DIR"
    iptables-save > "$BACKUP"
    echo "Sauvegarde des regles : $BACKUP"

    apply_rules
    echo "Regles appliquees."

    # Filet : restauration automatique si personne ne confirme
    setsid bash -c "sleep $DELAI; if [ -f '$ROLLBACK_PID' ]; then \
        iptables-restore < '$BACKUP'; rm -f '$ROLLBACK_PID'; \
        logger -t phpborg-fw 'ROLLBACK AUTOMATIQUE du firewall SSH'; fi" >/dev/null 2>&1 &
    echo $! > "$ROLLBACK_PID"

    echo
    echo "ROLLBACK AUTOMATIQUE dans $((DELAI/60)) minutes."
    echo "Ouvrez une NOUVELLE session SSH pour verifier que l'acces fonctionne,"
    echo "puis lancez :  $0 confirm"
    ;;

confirm)
    if [ -f "$ROLLBACK_PID" ]; then
        kill "$(cat "$ROLLBACK_PID")" 2>/dev/null
        rm -f "$ROLLBACK_PID"
        echo "Rollback annule : les regles restent en place."
        echo "Pour les rendre permanentes : apt install iptables-persistent"
        echo "                              netfilter-persistent save"
    else
        echo "Aucun rollback en attente."
    fi
    ;;

revert)
    revert_rules
    rm -f "$ROLLBACK_PID"
    echo "Regles retirees."
    ;;

status)
    echo "=== Branchement dans INPUT ==="
    iptables -S INPUT 2>/dev/null | grep -- "$CHAIN" || echo "  (non branche)"
    echo
    echo "=== Contenu de $CHAIN ==="
    iptables -L "$CHAIN" -n -v --line-numbers 2>/dev/null || echo "  (chaine absente)"
    echo
    echo "=== Rollback en attente ==="
    [ -f "$ROLLBACK_PID" ] && echo "  OUI (pid $(cat "$ROLLBACK_PID"))" || echo "  non"
    ;;

*)
    echo "Usage: $0 {check|apply|confirm|revert|status|boot}"
    exit 1
    ;;
esac
