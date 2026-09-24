-- Deploiement du nettoyage post-sauvegarde.
-- A lancer une fois :  mysql -h127.0.0.1 -uphpborg -p phpborg < conf/deploy-cleanup.sql

-- 1. Niveau A partout : logs, cache apt, coredumps, snapshot LVM orphelin.
UPDATE servers SET cleanup='@base' WHERE active=1;

-- 2. Machines Docker qui ne compilent pas : on ajoute le cache de build et
--    les images inutilisees.
UPDATE servers SET cleanup='@docker' WHERE active=1 AND name IN (
 'ab-notif-apps1','cartobio-preprod','cartobio','ab-nodejs2','ab-preprod',
 'ab-front','stats','ab-pg2','skynet','web1','ab-web-preprod','cezame-fle',
 'ab-web','ab-notif-bdd1','bdd-cartobio','yoda','else','ns0-net1c');

-- 3. ab-bastion compile : on purge ses images inutilisees mais on garde son
--    cache de construction, qui est son outil de travail.
UPDATE servers SET cleanup='@builder' WHERE name='ab-bastion';

-- 4. Filtre d'age du cache de build : aucun. Seul ab-bastion compile et il est
--    protege par son profil ; ailleurs ce cache est du dechet quel que soit
--    son age. Le filtre sur les images reste a 720h : sur ab-bastion, une
--    image fraichement construite et pas encore lancee ne doit pas disparaitre.
UPDATE settings SET value='' WHERE `key`='cleanup_cache_until';

-- 5. Trois machines n'excluaient pas /var/lib/docker/overlay2 alors que leurs
--    voisines le font. Ce n'etait pas un choix : ab-notif-apps1 sauvegarde
--    6,4 millions de fichiers la ou ab-front, correctement exclue, en
--    sauvegarde 950 000.
UPDATE repository r JOIN servers s ON s.id = r.server_id
   SET r.exclude = CONCAT(r.exclude, ',/var/lib/docker/overlay2')
 WHERE s.name IN ('ab-nodejs2','ab-notif-apps1','cartobio')
   AND r.type = 'backup'
   AND r.exclude NOT LIKE '%/var/lib/docker/overlay2%';

-- Verification
SELECT cleanup, COUNT(*) AS machines FROM servers WHERE active=1 GROUP BY cleanup;
SELECT s.name, r.exclude FROM repository r JOIN servers s ON s.id=r.server_id
 WHERE s.name IN ('ab-nodejs2','ab-notif-apps1','cartobio') AND r.type='backup';
