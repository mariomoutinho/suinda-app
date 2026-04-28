const SUINDA_DECK_OPTIONS_STORAGE_KEY = "suinda_deck_options";

const SUINDA_DEFAULT_DECK_OPTIONS = {
  dailyNewCardsLimit: 20,
  dailyReviewCardsLimit: 200,
  newCardsIgnoreReviewLimit: false,
  limitsStartFromParentDeck: false,
  learningSteps: "1m 10m",
  graduatingIntervalDays: 1,
  easyIntervalDays: 3,
  insertionOrder: "oldest"
};

let suindaCardProgressCache = null;
let suindaCardProgressMap = null;

function getCardProgressKey(userId, cardId) {
  return `${userId}:${cardId}`;
}

function getCardProgressMap() {
  if (!suindaCardProgressMap) {
    suindaCardProgressMap = new Map(
      getAllCardProgress().map(progress => [
        getCardProgressKey(progress.userId, progress.cardId),
        progress
      ])
    );
  }

  return suindaCardProgressMap;
}

function getDeckOptionsState() {
  const stored = getFromStorage(SUINDA_DECK_OPTIONS_STORAGE_KEY);

  if (!stored) {
    return {
      activePresetId: "default",
      presets: {
        default: {
          id: "default",
          name: "Default",
          options: { ...SUINDA_DEFAULT_DECK_OPTIONS }
        }
      },
      deckAssignments: {}
    };
  }

  if (stored.presets) {
    return {
      ...stored,
      deckAssignments: stored.deckAssignments || {}
    };
  }

  return {
    activePresetId: "default",
    presets: {
      default: {
        id: "default",
        name: "Default",
        options: { ...SUINDA_DEFAULT_DECK_OPTIONS, ...stored }
      }
    },
    deckAssignments: {}
  };
}

function saveDeckOptionsState(state) {
  saveToStorage(SUINDA_DECK_OPTIONS_STORAGE_KEY, state);
}

function getActiveDeckOptions() {
  return getDeckOptionsForDeck(null);
}

function getDeckPresetId(deckId = null) {
  const state = getDeckOptionsState();
  const assignedPresetId = deckId ? state.deckAssignments?.[String(deckId)] : null;
  const presetId = assignedPresetId || state.activePresetId || "default";
  return state.presets?.[presetId] ? presetId : "default";
}

function getDeckOptionsForDeck(deckId = null) {
  const state = getDeckOptionsState();
  const preset = state.presets?.[getDeckPresetId(deckId)] || state.presets?.default;
  return {
    ...SUINDA_DEFAULT_DECK_OPTIONS,
    ...(preset?.options || {})
  };
}

function getDeckPresetName(deckId = null) {
  const state = getDeckOptionsState();
  const preset = state.presets?.[getDeckPresetId(deckId)] || state.presets?.default;
  return preset?.name || "Default";
}

function assignDeckPreset(deckId, presetId) {
  const state = getDeckOptionsState();
  if (!state.presets?.[presetId]) return state;

  state.deckAssignments = state.deckAssignments || {};
  state.deckAssignments[String(deckId)] = presetId;
  saveDeckOptionsState(state);
  return state;
}

function getAllCardProgress() {
  if (!suindaCardProgressCache) {
    suindaCardProgressCache = getFromStorage("suinda_card_progress") || [];
  }

  return suindaCardProgressCache;
}

function saveAllCardProgress(progressList) {
  suindaCardProgressCache = progressList;
  suindaCardProgressMap = null;
  saveToStorage("suinda_card_progress", progressList);
}

function getCardProgress(userId, cardId) {
  return getCardProgressMap().get(getCardProgressKey(userId, cardId)) || null;
}

function getCardProgressForPlanning(userId, cardId) {
  return getCardProgress(userId, cardId) || getDefaultCardProgress(userId, cardId);
}

function upsertCardProgress(progress, options = {}) {
  const shouldSync = options.sync !== false;
  const all = getAllCardProgress();
  const index = all.findIndex(
    item => item.userId === progress.userId && item.cardId === progress.cardId
  );

  if (index >= 0) {
    all[index] = progress;
  } else {
    all.push(progress);
  }

  saveAllCardProgress(all);
  getCardProgressMap().set(getCardProgressKey(progress.userId, progress.cardId), progress);

  if (shouldSync && typeof apiSaveCardProgress === "function") {
    apiSaveCardProgress(progress).catch(error => {
      console.warn("Nao foi possivel salvar o progresso na API.", error);
    });
  }
}

function getOrCreateCardProgress(userId, cardId) {
  const existing = getCardProgress(userId, cardId);
  if (existing) return existing;

  const fresh = getDefaultCardProgress(userId, cardId);
  upsertCardProgress(fresh, { sync: false });
  return fresh;
}

function isDue(progress, now = new Date()) {
  if (progress.state === "suspended") return false;
  if (progress.state === "buried") {
    return Boolean(progress.dueAt) && new Date(progress.dueAt) <= now;
  }
  if (progress.state === "new") return true;
  if (!progress.dueAt) return true;
  return new Date(progress.dueAt) <= now;
}

function isLearnAheadEligible(progress, now = new Date()) {
  if (!progress.dueAt) return false;
  if (!(progress.state === "learning" || progress.state === "relearning")) return false;

  const dueTime = new Date(progress.dueAt).getTime();
  const nowTime = now.getTime();
  const diffMinutes = (dueTime - nowTime) / (60 * 1000);

  return diffMinutes > 0 && diffMinutes <= SUINDA_SCHEDULER_CONFIG.learnAheadLimitMinutes;
}

function getStatePriority(state) {
  if (state === "learning") return 1;
  if (state === "relearning") return 2;
  if (state === "review") return 3;
  if (state === "buried") return 3;
  if (state === "new") return 4;
  return 99;
}

function isWrongLearningProgress(progress) {
  return (progress.state === "learning" || progress.state === "relearning") &&
    (!progress.lastRating || progress.lastRating === "again");
}

function isReviewLikeLearningProgress(progress) {
  return (progress.state === "learning" || progress.state === "relearning") &&
    progress.lastRating &&
    progress.lastRating !== "again";
}

function getSchedulerDayKey(date = new Date()) {
  const day = new Date(date);
  const boundaryHour = SUINDA_SCHEDULER_CONFIG.nextDayStartsAtHour || 4;

  if (day.getHours() < boundaryHour) {
    day.setDate(day.getDate() - 1);
  }

  return day.toISOString().slice(0, 10);
}

function isSameSchedulerDay(dateString, now = new Date()) {
  if (!dateString) return false;
  return getSchedulerDayKey(new Date(dateString)) === getSchedulerDayKey(now);
}

function getDailyNewCardsLimit(deckId = null) {
  const options = getDeckOptionsForDeck(deckId);
  return Math.max(0, Number(options.dailyNewCardsLimit ?? SUINDA_SCHEDULER_CONFIG.dailyNewCardsLimit ?? 20));
}

function getDailyReviewCardsLimit(deckId = null) {
  const options = getDeckOptionsForDeck(deckId);
  return Math.max(0, Number(options.dailyReviewCardsLimit ?? SUINDA_SCHEDULER_CONFIG.dailyReviewCardsLimit ?? 200));
}

function normalizeDeckTitleForTree(title) {
  return String(title || "").trim();
}

function getDeckByIdForProgress(deckId) {
  return mockDecks.find(deck => Number(deck.id) === Number(deckId)) || null;
}

function isDeckDescendantOf(deck, parentDeck) {
  if (!deck || !parentDeck || Number(deck.id) === Number(parentDeck.id)) return false;

  const title = normalizeDeckTitleForTree(deck.title);
  const parentTitle = normalizeDeckTitleForTree(parentDeck.title);

  return Boolean(parentTitle) && title.startsWith(`${parentTitle}::`);
}

function getDeckScopeDeckIds(deckId) {
  const parentDeck = getDeckByIdForProgress(deckId);
  if (!parentDeck) return [deckId];

  return mockDecks
    .filter(deck => Number(deck.id) === Number(deckId) || isDeckDescendantOf(deck, parentDeck))
    .map(deck => Number(deck.id));
}

function getCardsForDeckScope(deckId) {
  const deckIds = new Set(getDeckScopeDeckIds(deckId));
  return mockCards.filter(card => deckIds.has(Number(card.deckId)));
}

function getNewCardsIntroducedTodayCount(userId, deckId, now = new Date()) {
  return mockCards
    .filter(card => Number(card.deckId) === Number(deckId))
    .reduce((total, card) => {
      const progress = getCardProgress(userId, card.id);
      return total + (isSameSchedulerDay(progress?.introducedAt, now) ? 1 : 0);
    }, 0);
}

function getNewCardsIntroducedTodayCountForDeckScope(userId, deckId, now = new Date()) {
  const deckIds = new Set(getDeckScopeDeckIds(deckId));

  return mockCards
    .filter(card => deckIds.has(Number(card.deckId)))
    .reduce((total, card) => {
      const progress = getCardProgress(userId, card.id);
      return total + (isSameSchedulerDay(progress?.introducedAt, now) ? 1 : 0);
    }, 0);
}

function getRemainingNewCardsLimit(userId, deckId, now = new Date()) {
  return Math.max(
    0,
    getDailyNewCardsLimit(deckId) - getNewCardsIntroducedTodayCount(userId, deckId, now)
  );
}

function getRemainingNewCardsLimitForDeckScope(userId, deckId, now = new Date()) {
  return Math.max(
    0,
    getDailyNewCardsLimit(deckId) - getNewCardsIntroducedTodayCountForDeckScope(userId, deckId, now)
  );
}

function sortDueItems(items) {
  return items.sort((a, b) => {
    const pa = getStatePriority(a.progress.state);
    const pb = getStatePriority(b.progress.state);

    if (pa !== pb) return pa - pb;

    const da = a.progress.dueAt ? new Date(a.progress.dueAt).getTime() : 0;
    const db = b.progress.dueAt ? new Date(b.progress.dueAt).getTime() : 0;
    return da - db;
  });
}

function getDirectDueCardsForDeck(userId, deckId, now = new Date()) {
  const cards = mockCards.filter(card => Number(card.deckId) === Number(deckId));
  const enriched = cards.map(card => {
    const progress = getCardProgressForPlanning(userId, card.id);
    return { card, progress };
  });

  const strictlyDue = sortDueItems(enriched.filter(item => isDue(item.progress, now)));

  if (strictlyDue.length > 0) {
    const dueLearning = strictlyDue.filter(item => isWrongLearningProgress(item.progress));
    const dueLearningReview = strictlyDue.filter(item => isReviewLikeLearningProgress(item.progress));
    const dueReview = strictlyDue
      .filter(item => item.progress.state === "review")
      .slice(0, getDailyReviewCardsLimit(deckId));
    const dueNew = strictlyDue
      .filter(item => item.progress.state === "new")
      .slice(0, getRemainingNewCardsLimit(userId, deckId, now));

    const limitedDue = [
      ...dueLearning,
      ...dueLearningReview,
      ...dueReview,
      ...dueNew
    ];

    if (limitedDue.length > 0) {
      return limitedDue;
    }
  }

  return sortDueItems(enriched.filter(item => isLearnAheadEligible(item.progress, now)));
}

function getDueCardsForDeck(userId, deckId, now = new Date()) {
  const scopeDeckIds = getDeckScopeDeckIds(deckId);
  const dueItems = sortDueItems(
    scopeDeckIds.flatMap(scopedDeckId => getDirectDueCardsForDeck(userId, scopedDeckId, now))
  );

  if (scopeDeckIds.length <= 1) {
    return dueItems;
  }

  const newLimit = getRemainingNewCardsLimitForDeckScope(userId, deckId, now);
  const reviewLimit = getDailyReviewCardsLimit(deckId);
  const newItems = [];
  const reviewItems = [];
  const learningItems = [];

  dueItems.forEach(item => {
    if (item.progress.state === "new") {
      newItems.push(item);
      return;
    }

    if (item.progress.state === "review" || isReviewLikeLearningProgress(item.progress)) {
      reviewItems.push(item);
      return;
    }

    learningItems.push(item);
  });

  return sortDueItems([
    ...learningItems,
    ...reviewItems.slice(0, reviewLimit),
    ...newItems.slice(0, newLimit)
  ]);
}

function isDueToday(dateString, now = new Date()) {
  if (!dateString) return false;

  const date = new Date(dateString);
  const nextBoundary = getNextDayBoundary(now);

  return date <= nextBoundary;
}

function getDirectStudyCountsForDeck(userId, deckId, now = new Date()) {
  const cards = mockCards.filter(card => Number(card.deckId) === Number(deckId));
  const counts = {
    new: 0,
    learning: 0,
    review: 0
  };

  cards.forEach(card => {
    const progress = getCardProgressForPlanning(userId, card.id);

    if (progress.state === "suspended") {
      return;
    }

    if (progress.state === "buried") {
      if (isDueToday(progress.dueAt, now)) {
        counts.review += 1;
      }
      return;
    }

    if (progress.state === "new") {
      counts.new += 1;
      return;
    }

    if (isWrongLearningProgress(progress)) {
      if (isDueToday(progress.dueAt, now)) {
        counts.learning += 1;
      }
      return;
    }

    if (progress.state === "review" || isReviewLikeLearningProgress(progress)) {
      if (isDueToday(progress.dueAt, now)) {
        counts.review += 1;
      }
    }
  });

  counts.new = Math.min(counts.new, getRemainingNewCardsLimit(userId, deckId, now));
  counts.review = Math.min(counts.review, getDailyReviewCardsLimit(deckId));

  return counts;
}

function getStudyCountsForDeck(userId, deckId, now = new Date()) {
  const scopeDeckIds = getDeckScopeDeckIds(deckId);
  const counts = scopeDeckIds
    .map(scopedDeckId => getDirectStudyCountsForDeck(userId, scopedDeckId, now))
    .reduce((total, counts) => ({
      new: total.new + counts.new,
      learning: total.learning + counts.learning,
      review: total.review + counts.review
    }), { new: 0, learning: 0, review: 0 });

  if (scopeDeckIds.length > 1) {
    counts.new = Math.min(counts.new, getRemainingNewCardsLimitForDeckScope(userId, deckId, now));
    counts.review = Math.min(counts.review, getDailyReviewCardsLimit(deckId));
  }

  return counts;
}
