# Video-Chat Demo Security Hardening Policy

Diese Policy trennt Demo-Scope-Findings in klare Entscheidungen: was akzeptiert
wird, was aktiv abgesichert werden muss und was aus dem aktiven Pfad entfernt
bleibt.

## Entscheidungsklassen

| Klasse | Bedeutung | Erlaubt im aktiven Demo-Pfad? |
| --- | --- | --- |
| `geschlossen/mitigiert` | Die technische Kontrolle existiert und bleibt Contract-Bestandteil. | Ja. |
| `absichern` | Das Finding betrifft einen erreichbaren Pfad und braucht eine technische Kontrolle. | Erst nach Kontrolle. |
| `akzeptiert/demo-only` | Das Risiko ist nur in isolierten lokalen Hilfs- oder Testpfaden akzeptiert. | Nein, ausser der Pfad ist lokal gebunden und explizit als Dev-only dokumentiert. |
| `entfernen` | Der Pfad ist historisch/legacy und darf nicht wieder als Default aktiv werden. | Nein. |

## Nicht verhandelbare Regeln

1. Request-Payloads duerfen keine aktive User-Identity festlegen. Aktive HTTP-,
   WebSocket- und SFU-Pfade muessen `user_id`, Rolle und Call-Rechte aus der
   serverseitigen Session beziehungsweise aus persistierten Call-Daten ableiten.
2. Demo-Backends, Dev-Helper und MCP-Hosts duerfen keine remote erreichbaren
   Admin-/Shutdown-Kommandos ohne Gate anbieten. Ohne Token/Auth ist so ein
   Kommando nur fuer Loopback-Clients akzeptiert.
3. Release- und Supply-Chain-Skripte behandeln Archivpfade als untrusted input:
   Pfade werden validiert und `tar` bekommt Archiv-Entries immer nach `--`.
4. Dev-only-Kompatibilitaet muss opt-in bleiben. Ein neuer Default, Docker- oder
   CI-Pfad darf nicht stillschweigend auf unsichere Demo-Fallbacks zeigen.

## Finding-Matrix

| ID | Finding | Entscheidung | Verbindliche Kontrolle |
| --- | --- | --- | --- |
| `SEC-DS-001` | Tar option injection in `verify-release-supply-chain.sh`. | `geschlossen/mitigiert` | `archive_entry_path_is_safe` prueft den Manifest-Pfad; Extraction nutzt `tar -xOf "${archive}" -- "${manifest_entry}"`. Keine Risk-Acceptance. |
| `SEC-DS-002` | Demo backend accepts client-supplied `userId` enabling spoofing. | `entfernen` fuer Legacy-Backend, `absichern` fuer aktive Pfade | `demo/video-chat/dev-backend.mjs` bleibt ausserhalb des aktiven Demo-Pfads. Im aktiven `backend-king-php` werden User-Identity und Rechte serverseitig aus Session, Teilnehmerstatus und Call-Rolle abgeleitet. Eine Wiedereinfuehrung eines client-supplied `userId` ist nur als isolierter Fixture/Test erlaubt. |
| `SEC-DS-003` | McpHost accepts unauthenticated `STOP`/shutdown commands. | `akzeptiert/demo-only` nur fuer Loopback, sonst `absichern` | `demo/userland/flow-php/src/McpHost.php` akzeptiert `STOP` nur von Loopback-Peers. Nicht-Loopback-Peers bekommen einen Fehler; der Contract-Test `extension/tests/675-flow-php-mcp-host-stop-loopback-guard-contract.phpt` deckt das ab. |

## Review-Gate fuer neue Demo-Scope-Findings

Neue Findings duerfen nicht nur als "Demo" abgehakt werden. Jedes neue
Demo-Scope-Finding braucht vor Close:

1. eine Finding-ID in dieser Datei,
2. eine Entscheidung aus der Matrix oben,
3. einen konkreten Kontrollpunkt oder eine explizite Dev-only-Grenze,
4. einen Contract-/Smoke-Check, wenn die Kontrolle statisch pruefbar ist.

Der statische Gate laeuft mit:

```bash
bash demo/video-chat/scripts/check-security-hardening-policy.sh
```
