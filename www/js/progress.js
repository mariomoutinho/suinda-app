function getStudyHistory() {
  return getFromStorage("suinda_study_history") || [];
}

function saveStudyHistory(history) {
  saveToStorage("suinda_study_history", history);
}

function getLocalDateKey(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function getTodayStudyActivity() {
  const todayKey = getLocalDateKey();
  const activity = getFromStorage("suinda_today_study_activity");

  if (!activity || activity.day !== todayKey) {
    return {
      day: todayKey,
      totalCards: 0,
      totalStudyTimeInSeconds: 0
    };
  }

  return activity;
}

function recordStudyAnswerActivity(answerData = {}) {
  const answeredAt = answerData.answeredAt ? new Date(answerData.answeredAt) : new Date();
  const day = getLocalDateKey(answeredAt);
  const current = getTodayStudyActivity();
  const activity = current.day === day
    ? current
    : { day, totalCards: 0, totalStudyTimeInSeconds: 0 };

  activity.totalCards += 1;
  activity.totalStudyTimeInSeconds += Math.max(
    1,
    Math.round(answerData.durationInSeconds || 1)
  );

  saveToStorage("suinda_today_study_activity", activity);
  return activity;
}

async function saveStudySession(sessionData) {
  const history = getStudyHistory();
  history.push(sessionData);
  saveStudyHistory(history);

  if (typeof apiSaveStudySession === "function") {
    return apiSaveStudySession(sessionData).catch(error => {
      console.warn("Nao foi possivel salvar a sessao na API.", error);
    });
  }

  return Promise.resolve();
}

function getSessionKey(session) {
  return [
    session.deckId || "",
    session.createdAt || "",
    session.finishedAt || "",
    session.total || 0,
    session.durationInSeconds || 0
  ].join("|");
}

function mergeStudyHistory(localHistory, remoteHistory) {
  const merged = [];
  const seen = new Set();

  [...(remoteHistory || []), ...(localHistory || [])].forEach(session => {
    const key = getSessionKey(session);
    if (seen.has(key)) return;
    seen.add(key);
    merged.push(session);
  });

  return merged.sort((a, b) => {
    const dateA = new Date(a.createdAt || 0).getTime();
    const dateB = new Date(b.createdAt || 0).getTime();
    return dateA - dateB;
  });
}

function getProgressSummary() {
  const history = getStudyHistory();

  const summary = {
    totalSessions: history.length,
    totalCards: 0,
    wrong: 0,
    hard: 0,
    easy: 0,
    veryEasy: 0,
    totalStudyTimeInSeconds: 0
  };

  history.forEach(session => {
    summary.totalCards += session.total || 0;
    summary.wrong += session.wrong || 0;
    summary.hard += session.hard || 0;
    summary.easy += session.easy || 0;
    summary.veryEasy += session.veryEasy || 0;
    summary.totalStudyTimeInSeconds += session.durationInSeconds || 0;
  });

  return summary;
}

function isToday(dateString) {
  const date = new Date(dateString);
  return getLocalDateKey(date) === getLocalDateKey();
}

function getTodayStudySummary() {
  const history = getStudyHistory();
  const todaySessions = history.filter(session => session.createdAt && isToday(session.createdAt));

  const summary = {
    totalSessions: todaySessions.length,
    totalCards: 0,
    totalStudyTimeInSeconds: 0,
    averageSecondsPerCard: 0
  };

  todaySessions.forEach(session => {
    summary.totalCards += session.total || 0;
    summary.totalStudyTimeInSeconds += session.durationInSeconds || 0;
  });

  const activity = getTodayStudyActivity();
  summary.totalCards = Math.max(summary.totalCards, activity.totalCards || 0);
  summary.totalStudyTimeInSeconds = Math.max(
    summary.totalStudyTimeInSeconds,
    activity.totalStudyTimeInSeconds || 0
  );

  if (summary.totalCards > 0) {
    summary.averageSecondsPerCard = Math.round(summary.totalStudyTimeInSeconds / summary.totalCards);
  }

  return summary;
}

function formatSecondsToTime(totalSeconds) {
  const seconds = Math.max(0, totalSeconds || 0);
  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = seconds % 60;

  if (minutes === 0) {
    return `${remainingSeconds}s`;
  }

  return `${minutes}min ${remainingSeconds}s`;
}
