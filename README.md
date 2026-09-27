# Gephýra

Gephýra (dal greco «ponte») collega il sito della scuola ai **gestionali e registri elettronici** usati dalla segreteria (Argo, Axios e altri) tramite le API che i fornitori mettono a disposizione secondo il modello di interoperabilità AgID.

## Moduli

| Modulo | Contiene |
|---|---|
| `gephyra` | parte comune: impostazioni, token OAuth2, chiamate alle API, registro degli errori, `drush gephyra:prova` |
| `gephyra_argo` | fornitore Argo: API di interoperabilità AgID di Argo Software (token su `auth.portaleargo.it` con scope `agid`, intestazione Basic) |

Ogni fornitore è un plugin `GephyraFornitore` dichiarato dal proprio sottomodulo in `src/Plugin/GephyraFornitore`: sa dove chiedere il token e dove chiamare i servizi. Il resto del sito usa solo il servizio `gephyra.client`, senza sapere quale fornitore c'è dietro.

## Credenziali

Le credenziali delle API (client ID e secret) sono rilasciate dal fornitore a ciascuna scuola e danno accesso ai dati personali di alunni e personale: **non vanno mai scritte nel codice, nella configurazione esportata o in Git**. Si scrivono nel `settings.php` del sito (o in `settings.local.php`), con l'id del fornitore come chiave:

```php
$settings['gephyra']['argo'] = [
  'client_id' => '…',
  'client_secret' => '…',
];
```

La pagina delle impostazioni dice solo se ci sono, senza mostrarle. Nel registro di Drupal (canale `gephyra`) finiscono solo fornitore, servizio e codice HTTP degli errori: mai credenziali, token o dati restituiti.

## Uso

- Impostazioni: `/admin/config/services/gephyra` (permesso «Amministrare Gephýra», solo per l'amministratore): fornitore, tempo massimo di attesa, pulsante «Prova collegamento».
- Dal terminale: `drush gephyra:prova [fornitore]`.
- Dal codice:

```php
$dati = \Drupal::service('gephyra.client')->richiesta('POST', 'personale/anagrafiche', ['anno' => 2026]);
```
