# Prompt for MCA-web-APP: move to API v3 and rework the settings section

> Paste this into a coding agent working in the **MCA-web-APP** repository. The API side is done in MCA-backend-and-API
> (branch `coding`): the contract is `www_data/api/moncycle_app_open_api.yaml` there, which is the only source of truth. Read its
> introduction and `getAccount` / `updateAccount` first. Follow this repository's own `AGENTS.md` (CSP rules, forms, `store.js`,
> `config.js`, French UI texts, i18n files).

## 1. Context: what changed in the API

The API is now **v3**, served under `/api/v3/` (`/api/v2/` answers 404). It is a breaking change, and v3 is also the number the web
app declares in `src/version.json` (`api` range): update it. Three groups of changes matter to the web app.

### 1.1 Address

- `src/config.json` `apiUrl`: `api/v2` -> `api/v3`; check `src/js/core/config.js`, `README.md`, `AGENTS.md`, `test/**` (fake API,
  e2e global setup, fixtures) and every `/api/v2` string (`store.js`, `cycles.js`, `day.js`, `news.js`, `captcha.js`, the settings
  `common.js`, `appareils.js`, `donnees.js`, the auth / register / recover / verify_email screens).

### 1.2 The account is reshaped (`GET /account`, `POST /account`)

Removed from the account: `sponsor` (the whole feature is gone: UI, store, types), and the flat `timelineAscending`,
`autoMailExport`, `notificationEmails`.

`GET /account` now answers, flat as before: `userId, loginEmail, method, temperatureTracking, birthYear, language, nickname,
inscriptionDate, oldestDay, research, totpState, lastWriteClientUtc`, plus two blocks:

```json
"settings": {
  "timelineAscending":  {"value": true,  "isSet": false, "default": true},
  "autoMailExport":     {"value": false, "isSet": false, "default": false},
  "dayBackgroundColor": {"value": true,  "isSet": false, "default": true},
  "dateFormat":         {"value": "DMY_SLASH", "isSet": false, "default": "DMY_SLASH"},
  "temperatureUnit":    {"value": "C", "isSet": false, "default": "C"},
  "notificationEmails": {"value": "", "isSet": false, "default": ""}
},
"customSettings": {}
```

- `value` is what the app uses (the default when `isSet` is `false`). Use `value`; show "default" state in the UI only if useful.
- **`POST /account` writes flat keys, and only the ones sent**: `{"temperatureUnit": "F"}`, `{"dayBackgroundColor": false}`.
  **`null` puts an optional setting back to its default** (`{"dateFormat": null}`). `notificationEmails` still needs `password` in
  the same body. The answer is the whole account (same shape as `GET`) and `meta.fieldsUpdated`.
- `customSettings` is for third-party apps: the web app does not need to use it, but it must **ignore** it, and never send it back
  in a way that erases keys (only send `customSettings` if it means to change a key).
- The default date format depends on the account `language` (`DE`, `PL`: `DMY_DOT`; others `DMY_SLASH`): when the user changes the
  language, the displayed `dateFormat` value changes if it is not `isSet`. Re-read `settings` from the answer of the POST.
- A wrong value is `400 validation_failed` with `details.issues` (field names are the JSON keys). Show the message by `code`
  like for the other forms.
- ETag/304 behaviour of `GET /account` is unchanged.

### 1.3 New optional settings, and what they mean for the display

| Setting | Values | Where the client applies it |
|---|---|---|
| `dayBackgroundColor` | boolean, default `true` | The new design draws a background colour per day (timeline, cycle view, day form). When `false`, draw no colour (neutral background); stamps and text stay. |
| `dateFormat` | `DMY_SLASH` 31/12/26, `DMY_DOT` 31.12.26, `DMY_DASH` 31-12-26, `MDY_SLASH` 12/31/26, `YMD_DASH` 26-12-31, `YMD_SLASH` 26/12/31, `YMD_DOT` 26.12.31 | **Every date shown to the user** (timeline, cycle headers, day form, exports list, settings): the year is on two digits. Dates (not times) only. Dates sent to or read from the API stay ISO `YYYY-MM-DD`: this is display only. Date inputs must parse and print the chosen format. |
| `temperatureUnit` | `C` or `F` | **The API and the database always carry Celsius.** Convert for display (F = C x 9/5 + 32) and convert back to Celsius (rounded to 2 decimals) before sending a day. Applies to the day form, the temperature curve, its axis and the day list. The PDF/CSV exports made by the server already follow the setting. |
| `timelineAscending`, `autoMailExport` | boolean | Same meaning, new location. **`autoMailExport` now defaults to `false`**: the cycle export is mailed only to accounts that switched it on (existing accounts keep the value they had). The Notifications panel must show it off for an account that never set it. |

Write one small module (for instance `src/js/core/format.js`) with `formatDate(iso)`, `parseDate(text)`, `formatTemperature(celsius)`,
`parseTemperature(text)` driven by the store's settings, and use it everywhere instead of ad hoc `toLocaleDateString` / `toFixed`.
Unit-test it (`test/`) for the 7 formats and the C/F round trip.

## 2. Store (`src/js/model/store.js`, `types.js`)

- Keep the account as the server gives it, including `settings` and `customSettings` (types.js). Expose selectors
  `setting(name)` returning `settings[name].value`.
- `store.js` has a local twin of the server logic (`day_from_body`, `recompute_cycles`): temperatures in it stay Celsius.
- Update the offline/optimistic update of the account: a setting changed locally changes `settings[name] = {value, isSet: true, default}`;
  a reset (`null`) sets `{value: default, isSet: false, default}`.
- Remove `sponsor` everywhere (also tests and fake API).

## 3. Rework the settings section (`src/screens/settings/`)

Current panels: `suivi.js` (follow-up: method, temperature tracking, timeline order...), `notifications.js` (mail), plus
`appareils.js`, `donnees.js` and `common.js`. Rework the screen so that preferences are grouped and every optional setting has
its control, using the existing form and i18n conventions (French texts in `src/i18n`, no inline script, CSP rules of AGENTS.md):

1. **Compte / Profil**: nickname, birth year, language, research consent (mandatory settings: one `POST /account` with only the
   changed field, as today). The `sponsor` control is removed.
2. **Affichage** (new panel, optional settings, each saves on change with a single-key POST and shows the saved state):
   - "Ordre de la frise": `timelineAscending` (moved from `suivi.js`).
   - "Couleur de fond des jours": `dayBackgroundColor` (switch).
   - "Format de la date": `dateFormat` (select of the 7 formats, each option shows today's date written in that format, plus a
     "Par défaut (selon la langue)" entry that sends `null`).
   - "Unité de température": `temperatureUnit` (C / F), only shown when `temperatureTracking` is on (the setting is kept either way).
   - Each optional control has a "Rétablir la valeur par défaut" action (sends `null`) enabled when `isSet` is `true`.
3. **Notifications**: `autoMailExport` and `notificationEmails` (still asks the current password in the same request, as today;
   `null`/`""` empties the list; the server limit is 255 characters in all, 5 addresses: read `limits.notificationEmailsMax` from
   `server_info`).
4. **Sécurité** (password, login address, two-factor), **Appareils**, **Données** unchanged except the `/api/v3` address.

A change refused by the server (`400`) must put the control back to its previous value and show the issue message.
The settings page must work for the demo accounts (the three password-protected changes answer `403 demo_account`: keep the
current handling).

## 4. Tests

- Update the fake API (`test/support/fake_api.mjs`) to the v3 shapes above (including `null` reset) and the e2e specs
  (`settings.spec.mjs`, `timeline.spec.mjs`, `session.spec.mjs`, `language.spec.mjs`, `register.spec.mjs`) to `/api/v3`.
- Add tests: each optional setting round trip; default date format follows language when unset; a temperature entered in F is sent in C;
  `dayBackgroundColor: false` removes the colour; reset with `null`.
- Run the repository's test commands and report the result; do not commit.

## 5. Definition of done

- No `/api/v2`, no `sponsor`, no flat `timelineAscending` / `autoMailExport` / `notificationEmails` left in `src/` or `test/`.
- Every displayed date follows `dateFormat`, every displayed temperature follows `temperatureUnit`, the background colour follows
  `dayBackgroundColor`.
- The settings screen has the grouped panels above, French texts, keyboard accessible.
- `src/version.json` supports API 3.
