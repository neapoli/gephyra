# Gephýra

Gephýra (dal greco «ponte») collega il sito della scuola ai **gestionali e registri elettronici** usati dalla segreteria (Argo, Nuvola, Axios e altri) tramite le API e i servizi pubblici che i fornitori mettono a disposizione.

## Moduli

| Modulo | Contiene |
|---|---|
| `gephyra` | parte comune: impostazioni, credenziali (OAuth2 o token fisso), chiamate alle API e agli indirizzi pubblici, registro degli errori, `drush gephyra:prova` |
| `gephyra_argo` | fornitore Argo: API di interoperabilità AgID di Argo Software (token su `auth.portaleargo.it` con scope `agid`, intestazione Basic) |
| `gephyra_nuvola` | fornitore Nuvola di Madisoft: feed RSS pubblico delle circolari (senza credenziali), `drush gephyra-nuvola:feed`; token facoltativo per le [API](https://supporto.madisoft.it/portal/it/kb/articles/nuvola-api), mandato nell'intestazione `token` |

Ogni fornitore è un plugin `GephyraFornitore` dichiarato dal proprio sottomodulo in `src/Plugin/GephyraFornitore`: sa che credenziali servono (e se sono obbligatorie), come si mandano e dove chiamare i servizi. Il resto del sito usa solo il servizio `gephyra.client`, senza sapere quale fornitore c'è dietro.

## Credenziali

Le credenziali delle API (client ID e secret, oppure un token fisso) sono rilasciate dal fornitore a ciascuna scuola e danno accesso ai dati personali di alunni e personale: **non vanno mai scritte nel codice, nella configurazione esportata o in Git**. Si scrivono nel `settings.php` del sito (o in `settings.local.php`), con l'id del fornitore come chiave:

```php
$settings['gephyra']['argo'] = [
  'client_id' => '…',
  'client_secret' => '…',
];
$settings['gephyra']['nuvola'] = [
  'token' => '…',
];
```

La pagina delle impostazioni dice solo se ci sono, senza mostrarle. Nel registro di Drupal (canale `gephyra`) finiscono solo fornitore, servizio e codice HTTP degli errori: mai credenziali, token o dati restituiti.

## Nuvola: feed delle circolari

L'indirizzo del feed si incolla nella pagina delle impostazioni, ad esempio `https://nuvola.madisoft.it/feed/LCIS01200Q/rss/2`: contiene il codice meccanografico e il numero della bacheca, che cambia da scuola a scuola (si trova nella pagina pubblica della bacheca). Il feed dà per ogni voce solo titolo, link al documento, data, id e voce del titolario; nella stessa bacheca Nuvola pubblica anche avvisi e bandi, che si distinguono per il titolario (impostabile, predefinito `UNICO`). `drush gephyra-nuvola:feed [--tutte]` elenca le voci senza importarle.

### Importazione

Con «Importa col cron le circolari nuove» attivo, a ogni cron le circolari del feed pubblicate dalla data di partenza (impostabile) vengono create sul sito **già pubblicate e promosse in prima pagina**:

| Campo | Valore |
|---|---|
| titolo, abstract | oggetto della circolare (feed), solo con la prima lettera maiuscola |
| data | data di pubblicazione su Nuvola (feed) |
| numero | «Numero registro» della tabella della bacheca; se non si trova, la circolare si importa senza numero e c'è un avviso nel registro |
| anno scolastico | dalla data (da settembre), termine cercato per nome |
| argomento, tipologia, destinatari | termini indicati per nome nelle impostazioni (predefiniti «Circolari», «Senza richiesta di presa visione», «Tutta la popolazione»); se uno manca non si importa nulla |
| allegati | tutti i file della pagina del documento, con il nome leggibile; se la pagina non si legge si riprova al cron successivo |
| testo | quello predefinito del tipo di contenuto |
| autore | utente indicato nelle impostazioni (predefinito admin) |

Con «Importa come notizie le voci che non sono circolari» attivo (sezione propria nelle impostazioni), le voci con un altro titolario diventano **notizie** pubblicate e promosse: titolo e abstract dall'oggetto, data di Nuvola, tipologia e argomento impostabili (predefiniti «Le notizie», «Notizie»), testo fisso impostabile (il corpo è obbligatorio), autore e data di partenza propri, file in un paragrafo «Allegati» di `field_extra_info`. Si possono escludere voci del titolario (una per riga). Con «Togli la pubblicazione alla data di archiviazione di Nuvola» (attiva di partenza) la «Data archiviazione» della pagina del documento diventa la spubblicazione programmata di **Scheduler** (`unpublish_on`, ore 00:00): serve Scheduler attivo e abilitato a spubblicare le notizie, altrimenti la notizia resta pubblicata e c'è un avviso nel registro. Solo per le notizie: le circolari restano come archivio.

Ogni voce importata si ricorda per id Nuvola (key-value `gephyra_nuvola.importate`) e non si importa più, nemmeno se la circolare viene cancellata dal sito; una circolare già presente con lo stesso numero e anno scolastico viene collegata e non ricreata. Le mail delle prese visione non partono. `drush gephyra-nuvola:importa [--prova] [--solo=circolari|notizie]` fa lo stesso dal terminale: senza `--solo` tratta i tipi attivi nelle impostazioni, con `--solo` quel tipo anche se è spento; con `--prova` non scarica e non salva nulla.

## Uso

- Impostazioni: `/admin/config/services/gephyra` (permesso «Amministrare Gephýra», solo per l'amministratore): fornitore, tempo massimo di attesa, pulsante «Prova collegamento».
- Dal terminale: `drush gephyra:prova [fornitore]`.
- Dal codice:

```php
$dati = \Drupal::service('gephyra.client')->richiesta('POST', 'personale/anagrafiche', ['anno' => 2026]);
```
