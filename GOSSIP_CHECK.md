# Gossip-Primary Abnahmeformular

Zweck:
- Dieses Dokument ist ein Formular fuer die Abnahme des Gossip-Primary
  Video-Media-Carrier-Sprints.
- Dieses Dokument ist keine durchgefuehrte Abnahme und keine
  Produktionsfreigabe.
- Felder mit `Auszufuellen` muessen im Abnahmetermin durch den Pruefer oder
  die Prueferin befuellt werden.

## 1. Abnahme-Metadaten

| Feld | Wert |
| --- | --- |
| Pruefer | Auszufuellen |
| Rolle | Auszufuellen |
| Datum | Auszufuellen |
| Branch | Auszufuellen |
| Commit | Auszufuellen |
| Umgebung | Auszufuellen |
| Build/Asset-Version | Auszufuellen |
| Backend-Version | Auszufuellen |
| SFU-Version | Auszufuellen |
| Test-Call-ID | Auszufuellen |

## 2. Scope

Zu pruefender Zielzustand:
- Head-Server ist Control Plane fuer Join, Admission, Identity, Topology Hints,
  Neighbor Assignment, Repair, Telemetry und SFU-Fallback.
- Media-Fanout kann in `gossip_primary` primaer ueber bounded Gossip
  Peer-Verbindungen laufen.
- SFU bleibt als Fallback, Relay und Recording-Pfad erhalten.
- SFU-Fehler blockieren in `gossip_primary` kein Gossip-Publishing.
- Der Server uebernimmt keinen normalen Media-Fanout.

Nicht Teil dieser Abnahme:
- Neues Deployment ausloesen.
- Produktivdaten veraendern.
- Feature-Scope reduzieren, um eine Freigabe zu erreichen.

## 3. Konfiguration

| Einstellung | Erwartung | Beobachtung |
| --- | --- | --- |
| `VITE_VIDEOCHAT_MEDIA_CARRIER=gossip_primary` | Gossip darf ohne SFU-Ready publishen | Auszufuellen |
| `VITE_VIDEOCHAT_MEDIA_CARRIER=sfu_first` | SFU bleibt vor Gossip erforderlich | Auszufuellen |
| `VITE_VIDEOCHAT_MEDIA_CARRIER=sfu_mirror` | SFU zuerst, Gossip als optionaler Mirror | Auszufuellen |
| `VITE_VIDEOCHAT_GOSSIP_DATA_LANE=active` | Gossip Data Lane publish/receive aktiv | Auszufuellen |

## 4. Pflicht-Checks

| ID | Check | Erwartung | Ergebnis | Evidenz |
| --- | --- | --- | --- | --- |
| GSP-C01 | Runtime Mode | `gossip_primary`, `sfu_first`, `sfu_mirror` sind explizit verfuegbar | Auszufuellen | Auszufuellen |
| GSP-C02 | Publisher Pipeline | In `gossip_primary`: `encode -> publish gossip -> optional SFU` | Auszufuellen | Auszufuellen |
| GSP-C03 | SFU Failure Tolerance | SFU unavailable/send-failed verhindert Gossip-Publish in `gossip_primary` nicht | Auszufuellen | Auszufuellen |
| GSP-C04 | Join Topology | Join/Snapshot liefern admitted peers, capabilities, room/call identity, transport candidates, assigned neighbors | Auszufuellen | Auszufuellen |
| GSP-C05 | Churn Topology | `room/joined` und `room/left` liefern peer-scoped Topology Updates | Auszufuellen | Auszufuellen |
| GSP-C06 | Dedicated Neighbors | Clients bauen nur server-assigned bounded Gossip DataChannels | Auszufuellen | Auszufuellen |
| GSP-C07 | Repair | Neighbor-Failure erzeugt Repair Request, Server reassigned, alte Edge wird retired | Auszufuellen | Auszufuellen |
| GSP-C08 | Rollout Gate | `gossip_primary` gate basiert auf Gossip Topology Health, nicht SFU Baseline | Auszufuellen | Auszufuellen |
| GSP-C09 | Recovery | Keyframe Requests, Frame Cache, Missing-Frame Retransmit, Duplicate Suppression, TTL/Fanout Limits funktionieren | Auszufuellen | Auszufuellen |
| GSP-C10 | No Server Media Fanout | WebSocket Control Plane akzeptiert keine normalen Media-Fanout Commands | Auszufuellen | Auszufuellen |

## 5. Empfohlene lokale Nachweise

Die folgenden Commands sind Vorschlaege fuer den Abnahmetermin. Ergebnisse hier
nicht vorab eintragen.

```bash
cd demo/video-chat/frontend-vue
npm run test:contract:gossip
```

```bash
cd demo/video-chat/frontend-vue
node tests/contract/gossip-media-carrier-integration-smoke-contract.mjs
```

```bash
cd demo/video-chat/frontend-vue
npm run build
```

```bash
git diff --check
```

## 6. Manuelle Call-Abnahme

| Schritt | Erwartung | Ergebnis | Notizen |
| --- | --- | --- | --- |
| 1 | Zwei Teilnehmer koennen dem Call beitreten | Auszufuellen | Auszufuellen |
| 2 | Dritter Teilnehmer triggert Churn ohne Media-Abbruch | Auszufuellen | Auszufuellen |
| 3 | Remote Video wird sichtbar und bleibt sichtbar | Auszufuellen | Auszufuellen |
| 4 | SFU-Send-Fehler oder SFU-Unready blockiert Gossip in `gossip_primary` nicht | Auszufuellen | Auszufuellen |
| 5 | Neighbor-Ausfall wird durch Server-Reassignment repariert | Auszufuellen | Auszufuellen |
| 6 | Recovery-Signale bleiben begrenzt und fuehren nicht zu Recovery-Storms | Auszufuellen | Auszufuellen |
| 7 | Server-Logs zeigen keinen normalen Media-Fanout ueber Control Plane | Auszufuellen | Auszufuellen |

## 7. Telemetrie und Logs

| Quelle | Zu pruefen | Ergebnis | Link/Log-Auszug |
| --- | --- | --- | --- |
| Browser Console | keine fatalen Gossip/SFU Runtime Errors | Auszufuellen | Auszufuellen |
| Client Diagnostics | Carrier Mode, Gate Decision, Topology Epoch, Neighbor Count | Auszufuellen | Auszufuellen |
| Backend Logs | Join, topology hint, repair, recovery ops | Auszufuellen | Auszufuellen |
| SFU Logs | Fallback/Relay bleibt separat, kein blockierender Gossip-Pfad | Auszufuellen | Auszufuellen |
| Server Metrics | CPU/RAM/Socket-Last waehrend Call | Auszufuellen | Auszufuellen |

## 8. Restrisiken

| Risiko | Bewertung | Massnahme | Owner |
| --- | --- | --- | --- |
| Auszufuellen | Auszufuellen | Auszufuellen | Auszufuellen |
| Auszufuellen | Auszufuellen | Auszufuellen | Auszufuellen |
| Auszufuellen | Auszufuellen | Auszufuellen | Auszufuellen |

## 9. Entscheidung

| Entscheidung | Auswahl |
| --- | --- |
| Abgenommen | Auszufuellen |
| Abgenommen mit Auflagen | Auszufuellen |
| Nicht abgenommen | Auszufuellen |

Auflagen:
- Auszufuellen

Freigabe durch:
- Name: Auszufuellen
- Datum: Auszufuellen
- Signatur/Kommentar: Auszufuellen
