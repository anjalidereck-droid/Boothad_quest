/*
  api.js
  ======
  One small helper for talking to WordPress (the plugin's API, see includes/rest.php).

  DQApi.call("skills")                                   -> a GET request
  DQApi.call("answer", { question_id: 5, chosen: 2 })    -> a POST request with JSON
  DQApi.call("next-question", null, { skill: "Fractions" })  -> a GET with extra options

  After login the server gives us a TOKEN. We keep it in DQApi.token and send it
  in the "X-DQ-Token" header on every request, so the server knows who is playing.

  DQ_CONFIG.api (the API address) is filled in by WordPress, see includes/shortcode.php.
*/

const DQApi = {
  token: null,
  onLoggedOut: null,     // game.js sets this: what to do if the token stops working

  // Build the full address. It works whether your permalinks are "pretty" or "plain".
  url(path, params) {
    let address = DQ_CONFIG.api + path;
    if (params) {
      const query = new URLSearchParams(params).toString();
      if (query) address += (address.indexOf("?") >= 0 ? "&" : "?") + query;
    }
    return address;
  },

  async call(path, body, params) {
    const headers = {};
    if (body) headers["Content-Type"] = "application/json";
    if (this.token) headers["X-DQ-Token"] = this.token;

    let response;
    try {
      response = await fetch(this.url(path, params), {
        method: body ? "POST" : "GET",
        headers: headers,
        body: body ? JSON.stringify(body) : undefined,
        credentials: "omit",            // we do not use WordPress cookies for children
      });
    } catch (err) {
      return { error: "Cannot reach the server. Check your internet and try again." };
    }

    let data = {};
    try { data = await response.json(); } catch (err) { /* the reply was not JSON */ }

    // WordPress sends errors as { code, message }. We turn that into { error }.
    if (!response.ok) {
      if (response.status === 401 && this.token && this.onLoggedOut) this.onLoggedOut();
      return { error: data.message || "Something went wrong. Please try again." };
    }
    return data;
  },
};

// localStorage can be blocked in some browsers. These wrappers stop the game crashing.
const dqStore = {
  get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
  set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* ignore */ } },
  remove(key) { try { localStorage.removeItem(key); } catch (e) { /* ignore */ } },
};
