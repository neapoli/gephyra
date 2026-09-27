# Gephýra

Gephýra (dal greco «ponte») collega il sito della scuola ai **gestionali e registri elettronici** usati dalla segreteria (Argo, Axios e altri) tramite le API che i fornitori mettono a disposizione secondo il modello di interoperabilità AgID.

Il modulo è in fase di progettazione.

## Credenziali

Le credenziali delle API (client ID e secret) sono rilasciate dal fornitore a ciascuna scuola e danno accesso ai suoi dati personali: **non vanno mai scritte nel codice, nella configurazione esportata o in Git**. Si conservano nel `settings.php` del sito (o in `settings.local.php`).
