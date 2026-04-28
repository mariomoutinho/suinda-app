const SUINDA_API_BASE_URL =
  localStorage.getItem("suinda_api_base_url") || "http://127.0.0.1:8000";

let suindaLastDataSource = "local";
let suindaApiSessionRecovery = null;

function getApiToken() {
  return localStorage.getItem("suinda_api_token");
}

function saveApiToken(token) {
  localStorage.setItem("suinda_api_token", token);
}

function clearApiToken() {
  localStorage.removeItem("suinda_api_token");
}

function setDataSource(source) {
  suindaLastDataSource = source;
  localStorage.setItem("suinda_last_data_source", source);
}

async function recoverApiSession() {
  if (suindaApiSessionRecovery) {
    return suindaApiSessionRecovery;
  }

  suindaApiSessionRecovery = (async () => {
    const currentUser = getFromStorage("suinda_current_user");
    const demoUser = mockUsers.find(user => (
      user.active && currentUser && user.email === currentUser.email
    ));

    if (!demoUser) {
      return false;
    }

    try {
      const apiUser = await apiLogin(demoUser.email, demoUser.password);
      saveToStorage("suinda_current_user", apiUser);
      return true;
    } catch (error) {
      clearApiToken();
      return false;
    }
  })();

  const recovered = await suindaApiSessionRecovery;
  suindaApiSessionRecovery = null;
  return recovered;
}

async function apiRequest(path, options = {}) {
  const {
    auth = true,
    headers: optionHeaders = {},
    _retriedAuth = false,
    ...fetchOptions
  } = options;

  const headers = {
    "Content-Type": "application/json",
    ...optionHeaders
  };

  if (auth !== false) {
    const token = getApiToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
  }

  const response = await fetch(`${SUINDA_API_BASE_URL}${path}`, {
    ...fetchOptions,
    headers
  });

  const text = await response.text();
  const objectStart = text.indexOf("{");
  const arrayStart = text.indexOf("[");
  const starts = [objectStart, arrayStart].filter(index => index >= 0);
  const jsonStart = starts.length ? Math.min(...starts) : -1;
  const jsonText = jsonStart >= 0 ? text.slice(jsonStart) : text;
  const data = JSON.parse(jsonText || "{}");

  if (!response.ok) {
    if (response.status === 401 && auth !== false && !_retriedAuth) {
      clearApiToken();

      if (await recoverApiSession()) {
        return apiRequest(path, {
          ...fetchOptions,
          headers: optionHeaders,
          auth,
          _retriedAuth: true
        });
      }
    }

    const error = new Error(data.error || "Erro ao comunicar com a API.");
    error.status = response.status;
    throw error;
  }

  return data;
}

async function apiLogin(email, password) {
  const data = await apiRequest("/auth/login", {
    method: "POST",
    auth: false,
    body: JSON.stringify({ email, password })
  });

  saveApiToken(data.token);
  return data.user;
}

async function apiGetDecks() {
  const data = await apiRequest("/decks");
  return data.decks || [];
}

async function apiGetDeck(id) {
  const data = await apiRequest(`/decks/${id}`);
  return data.deck;
}

async function apiGetCards(deckId, options = {}) {
  const query = options.includeMedia === false ? "?includeMedia=0" : "";
  const data = await apiRequest(`/decks/${deckId}/cards${query}`);
  return data.cards || [];
}

async function apiGetCard(cardId) {
  const data = await apiRequest(`/cards/${cardId}`);
  return data.card;
}

async function apiCreateDeck(deckData) {
  const data = await apiRequest("/decks", {
    method: "POST",
    body: JSON.stringify(deckData)
  });

  return data.deck;
}

async function apiUpdateDeck(deckId, deckData) {
  const data = await apiRequest(`/decks/${deckId}`, {
    method: "PUT",
    body: JSON.stringify(deckData)
  });

  return data.deck;
}

async function apiDeleteDeck(deckId) {
  return apiRequest(`/decks/${deckId}`, { method: "DELETE" });
}

async function apiCreateCard(deckId, cardData) {
  const data = await apiRequest(`/decks/${deckId}/cards`, {
    method: "POST",
    body: JSON.stringify(cardData)
  });

  return data.card;
}

async function apiUpdateCard(cardId, cardData) {
  const data = await apiRequest(`/cards/${cardId}`, {
    method: "PUT",
    body: JSON.stringify(cardData)
  });

  return data.card;
}

async function apiDeleteCard(cardId) {
  return apiRequest(`/cards/${cardId}`, { method: "DELETE" });
}

async function apiImportCards(deckId, content) {
  return apiRequest("/import", {
    method: "POST",
    body: JSON.stringify({ deckId, content })
  });
}

async function apiImportApkg(deckId, file, options = {}) {
  const formData = new FormData();
  formData.append("deckId", String(deckId));
  formData.append("file", file);
  formData.append("autoCreateDeck", options.autoCreateDeck ? "1" : "0");
  formData.append("deckTitle", options.deckTitle || file.name.replace(/\.apkg$/i, ""));

  const headers = {};
  const token = getApiToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (typeof options.onProgress === "function") {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();

      xhr.open("POST", `${SUINDA_API_BASE_URL}/import/apkg`);

      Object.entries(headers).forEach(([key, value]) => {
        xhr.setRequestHeader(key, value);
      });

      xhr.upload.onprogress = event => {
        if (!event.lengthComputable) {
          options.onProgress({ phase: "upload", percent: 8 });
          return;
        }

        const percent = Math.max(8, Math.min(70, Math.round((event.loaded / event.total) * 70)));
        options.onProgress({
          phase: "upload",
          loaded: event.loaded,
          total: event.total,
          percent
        });
      };

      xhr.onload = () => {
        let data = {};

        try {
          const text = xhr.responseText || "";
          const objectStart = text.indexOf("{");
          const arrayStart = text.indexOf("[");
          const starts = [objectStart, arrayStart].filter(index => index >= 0);
          const jsonStart = starts.length ? Math.min(...starts) : -1;
          const jsonText = jsonStart >= 0 ? text.slice(jsonStart) : text;
          data = jsonText ? JSON.parse(jsonText) : {};
        } catch (error) {
          reject(new Error("A importacao retornou uma resposta invalida do backend."));
          return;
        }

        if (xhr.status < 200 || xhr.status >= 300) {
          reject(new Error(data.error || "Erro ao importar pacote Anki."));
          return;
        }

        options.onProgress({ phase: "done", percent: 100 });
        resolve(data);
      };

      xhr.onerror = () => {
        reject(new Error("Nao foi possivel conectar ao backend PHP. Rode: php -S 127.0.0.1:8000 -t backend/public"));
      };

      options.onProgress({ phase: "start", percent: 4 });
      xhr.send(formData);
    });
  }

  const response = await fetch(`${SUINDA_API_BASE_URL}/import/apkg`, {
    method: "POST",
    headers,
    body: formData
  }).catch(() => {
    throw new Error("Não foi possível conectar ao backend PHP. Rode: php -S 127.0.0.1:8000 -t backend/public");
  });
  const text = await response.text();
  const objectStart = text.indexOf("{");
  const arrayStart = text.indexOf("[");
  const starts = [objectStart, arrayStart].filter(index => index >= 0);
  const jsonStart = starts.length ? Math.min(...starts) : -1;
  const jsonText = jsonStart >= 0 ? text.slice(jsonStart) : text;
  const data = jsonText ? JSON.parse(jsonText) : {};

  if (!response.ok) {
    throw new Error(data.error || "Erro ao importar pacote Anki.");
  }

  return data;
}

async function apiGetTodayStats() {
  const data = await apiRequest("/stats/today");
  return data.stats;
}

async function apiGetDailyStats() {
  const data = await apiRequest("/stats/daily");
  return data.days || [];
}

async function apiSync(payload = {}) {
  return apiRequest("/sync", {
    method: "POST",
    body: JSON.stringify(payload)
  });
}

async function apiGetStudyHistory() {
  const data = await apiRequest("/study/history");
  return data.history || [];
}

async function apiSaveStudySession(sessionData) {
  return apiRequest("/study/sessions", {
    method: "POST",
    body: JSON.stringify(sessionData)
  });
}

async function apiGetCardProgress() {
  const data = await apiRequest("/cards/progress");
  return data.progress || [];
}

async function apiSaveCardProgress(progress) {
  return apiRequest(`/cards/${progress.cardId}/progress`, {
    method: "PUT",
    body: JSON.stringify(progress)
  });
}

function replaceArrayContent(target, source) {
  target.splice(0, target.length, ...source);
}

function mergeLocalContent() {
  const localDecks = getFromStorage("suinda_local_decks") || [];
  const localCards = getFromStorage("suinda_local_cards") || [];

  localDecks.forEach(deck => {
    if (!mockDecks.some(item => item.id === deck.id)) {
      mockDecks.push(deck);
    }
  });

  localCards.forEach(card => {
    if (!mockCards.some(item => item.id === card.id)) {
      mockCards.push(card);
    }
  });
}

mergeLocalContent();

async function loadDecksFromApi() {
  try {
    const decks = await apiGetDecks();
    replaceArrayContent(mockDecks, decks);
    removeFromStorage("suinda_local_cards");
    removeFromStorage("suinda_local_decks");
    setDataSource("api");
    return decks;
  } catch (error) {
    setDataSource("local");
    return mockDecks;
  }
}

async function loadDeckDetailFromApi(deckId) {
  try {
    const deck = await apiGetDeck(deckId);
    const index = mockDecks.findIndex(item => item.id === deck.id);

    if (index >= 0) {
      mockDecks[index] = deck;
    } else {
      mockDecks.push(deck);
    }

    return deck;
  } catch (error) {
    setDataSource("local");
    return mockDecks.find(item => item.id === deckId) || null;
  }
}

async function loadCardsFromApi(deckId, options = {}) {
  try {
    const includeMedia = options.includeMedia !== false;
    const cards = await apiGetCards(deckId, { includeMedia });
    const normalizedCards = includeMedia
      ? cards
      : cards.map(card => {
        const existing = mockCards.find(item => Number(item.id) === Number(card.id));
        return {
          ...existing,
          ...card,
          imageData: card.imageData ?? existing?.imageData ?? null,
          audioData: card.audioData ?? existing?.audioData ?? null
        };
      });
    const remaining = mockCards.filter(card => card.deckId !== deckId);
    replaceArrayContent(mockCards, [...remaining, ...normalizedCards]);
    setDataSource("api");
    return normalizedCards;
  } catch (error) {
    setDataSource("local");
    return mockCards.filter(card => card.deckId === deckId);
  }
}

async function loadStudyHistoryFromApi() {
  const localHistory = getStudyHistory();

  try {
    const history = await apiGetStudyHistory();
    const mergedHistory = mergeStudyHistory(localHistory, history);
    saveStudyHistory(mergedHistory);
    return mergedHistory;
  } catch (error) {
    return localHistory;
  }
}

async function loadTodayStatsFromApi() {
  try {
    return await apiGetTodayStats();
  } catch (error) {
    const summary = getTodayStudySummary();
    return {
      totalSessions: summary.totalSessions,
      totalCards: summary.totalCards,
      totalSeconds: summary.totalStudyTimeInSeconds,
      totalMinutes: Math.round((summary.totalStudyTimeInSeconds / 60) * 100) / 100,
      secondsPerCard: summary.averageSecondsPerCard
    };
  }
}

async function loadCardProgressFromApi() {
  try {
    const progress = await apiGetCardProgress();
    saveAllCardProgress(progress);
    return progress;
  } catch (error) {
    return getAllCardProgress();
  }
}
