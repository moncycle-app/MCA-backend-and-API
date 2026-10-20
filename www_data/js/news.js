/* The news of Tech 4 Fertility: the JSON file at NEWS_URL (the newsUrl of GET /api/v2/server_info), fetched and checked for the pages that
** show it (the banner of the timeline, the news of the settings). It is another site's data, so nothing of it is trusted: no cookie and
** no referrer go out, the answer must be JSON and small, only the four strings of the French item are kept (length-capped; the pages draw
** them with .text()), and the link must be https. A missing item (null), an item a month old or older and any failure give no item, and
** the details go to the console. */
const moncycle_news = {
	max_age_days : 30,
	max_chars : { title : 200, description : 600, url : 500 },
	max_response_chars : 16384,

	// A promise of the item {title, description, url, pubDate}, or of null when there is nothing to show. Never rejects.
	load : function (url) {
		return fetch(url, { credentials : "omit", referrerPolicy : "no-referrer", redirect : "error", headers : { Accept : "application/json" } })
			.then(function (response) {
				if (!response.ok) throw new Error("HTTP " + response.status + " " + response.statusText);
				let type = response.headers.get("Content-Type") || "";
				if (!/^application\/json\s*(;|$)/i.test(type)) throw new Error("unexpected Content-Type: " + type);
				return response.text();
			})
			.then(function (text) {
				if (text.length > moncycle_news.max_response_chars) throw new Error("answer too large: " + text.length + " characters");
				let news;
				try { news = JSON.parse(text); }
				catch (e) { throw new Error("JSON parsing failed: " + e.message); }
				return moncycle_news.item(news);
			})
			.catch(function (error) {
				console.error("news: not shown (" + url + "):", error);
				return null;
			});
	},

	// The French item of the file checked, or null when there is none to show (null, too old); throws on anything wrong.
	item : function (news) {
		if (news === null || typeof news !== "object" || Array.isArray(news)) throw new Error("the file is not an object");
		if (!("fr" in news)) throw new Error("no \"fr\" section");
		let item = news.fr;
		if (item === null) {
			console.info("news: the French section is null, nothing to show");
			return null;
		}
		if (typeof item !== "object" || Array.isArray(item)) throw new Error("the French section is not an object");
		let clean = {};
		for (let key of ["title", "description", "url", "pubDate"]) {
			if (typeof item[key] !== "string" || item[key].trim() === "") throw new Error("\"" + key + "\" is missing or not a text");
			clean[key] = item[key].trim();
		}
		for (let key in moncycle_news.max_chars) {
			if (clean[key].length > moncycle_news.max_chars[key]) throw new Error("\"" + key + "\" is too long: " + clean[key].length + " characters");
		}
		let link;
		try { link = new URL(clean.url); }
		catch (e) { throw new Error("\"url\" is not a URL: " + clean.url); }
		if (link.protocol !== "https:" || link.username !== "" || link.password !== "") throw new Error("\"url\" is not a plain https link: " + clean.url);
		clean.url = link.href;
		let published = Date.parse(clean.pubDate);
		if (isNaN(published)) throw new Error("\"pubDate\" is not a date: " + clean.pubDate);
		let limit = new Date();
		limit.setMonth(limit.getMonth() - 1);
		if (published <= limit.getTime()) {
			console.info("news: \"" + clean.title + "\" is a month old or older (" + clean.pubDate + "), nothing to show");
			return null;
		}
		return clean;
	},
};
