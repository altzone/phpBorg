#!/bin/bash

if [[ ! $1 ]]; then
        echo "merci d'entrer le host du serveur"
        exit 1

fi
filename=`date +%Y-%m-%d_%H:%M:%S`
echo "
| Creation du backup pour le serveur $1
_____________________________________________________"

echo -n  "Indiquez le nombre de point de retention [8] :"
read retention
echo -n  "Port SSH [22] :"
read sshport

echo  -n "Creation de la clé SSH => "
ssh $1 "if [ ! -f /root/.ssh/id_rsa ]; then ssh-keygen -t rsa -b 1024 -f /root/.ssh/id_rsa -N '' &> /dev/null && echo OK || echo FAIL; else  echo 'Skip .. clé existante'; fi "
key=`ssh $1 "cat /root/.ssh/id_rsa.pub"`

echo -n "Installation de Borg => "
ssh $1 "if [ `uname -m` == 'i686' ]; then plateforme='32'; else plateforme='64'; fi; if [ ! -f /usr/bin/borg ]; then wget --no-check-certificate -q -O /usr/bin/borg https://github.com/borgbackup/borg/releases/download/1.1.7/borg-linux\$plateforme  ; chmod +x /usr/bin/borg && echo OK || echo FAIL ; else echo 'Skip .. déja installé'; fi"

echo -n "Creation de l'utilisateur => "
if [ ! -d /data0/backup/$1 ]
then
        useradd -d /data0/backup/$1 -m $1
        mkdir -p /data0/backup/$1/.ssh
        echo $key > /data0/backup/$1/.ssh/authorized_keys
        echo "OK"
else
        echo 'Skip .. Utilisateur existant'
fi

echo -n "Creation du repository => "
if [ ! -d  /data0/backup/$1/backup ]
then
        cd  /data0/backup/$1/
        borg init backup -e none && echo OK || echo FAIL
else
        echo 'Skip .. repo existant'
fi
echo -n "Creation du repertoire de restoration => "
if [ ! -d /data0/backup/$1/restore ]
then
        mkdir /data0/backup/$1/restore && echo OK || echo FAIL
else
        echo 'Skip .. le repertoire existe deja'
fi

echo "Creation de la configuration"
echo -n  "    - Ajout du repertoire config"
if [ ! -d /data0/backup/$1/conf ]
then
        mkdir /data0/backup/$1/conf
        echo "[OK]"
else
        echo 'Skip .. le repertoire existe deja'
fi

echo -n  "    - Mise en place du fichier de config"
if [ ! -f /data0/backup/$1/conf/borg.conf ]
then
        [[ ! $retention ]] && retention=8
        [[ ! $sshport ]] && sshport=22
echo "
host=$1
port=$sshport
repo=/data0/backup/$1/backup
compression=lz4
ratelimit=0
backup=/
exclude=/proc,/dev,/sys,/tmp,/run,/var/run,/lost+found,/var/cache/apt/archives,/var/lib/mysql,/var/lib/lxcfs
retention=$retention
" > /data0/backup/$1/conf/borg.conf
echo "[OK]"
else
                echo "La config existe deja .. SKIP"
fi




echo -n 'Application des droits sur le repertoire => '
chown $1 /data0/backup/$1 -Rf && echo OK || echo FAIL
