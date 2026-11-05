/* moncycle.app
**
** Copyright (C) 2021-2026 Tech 4 Fertility, aka. T4F (https://tech4fertility.org)
** SPDX-License-Identifier: AGPL-3.0-or-later
**
** This program is free software: you can redistribute it and/or modify it under
** the terms of the GNU Affero General Public License as published by the Free
** Software Foundation, either version 3 of the License, or (at your option) any
** later version. It is distributed WITHOUT ANY WARRANTY; see the licence for details.
*/

// Starts Scalar on the spec the API serves. A file of its own: the CSP is script-src 'self', so no inline script.
// Not the id "api-reference": the bundle starts a second, unconfigured instance on an element that has it.
// Nothing on this page may call Scalar's own servers: no web fonts, no telemetry, no AI agent, no hosted client.
Scalar.createApiReference("#app", {
	url: "moncycle_app_open_api.yaml",
	// the relative server of the file (./v3): Scalar would read it from the root of the site, not from this page
	servers: [{ url: new URL("v3", location.href).href, description: "This server" }],
	withDefaultFonts: false,
	telemetry: false,
	agent: { disabled: true },
	mcp: { disabled: true },
	hideClientButton: true,
	showDeveloperTools: "never",
});
