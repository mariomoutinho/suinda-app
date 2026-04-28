const SUINDA_SCHEDULER_CONFIG = {
  dailyNewCardsLimit: 20,
  dailyReviewCardsLimit: 200,
  learningStepsMinutes: [1, 10],
  relearningStepsMinutes: [10],
  graduatingIntervalDays: 1,
  easyIntervalDays: 4,
  minimumIntervalDays: 1,
  startingEase: 2.5,
  easyBonus: 1.3,
  hardIntervalMultiplier: 1.2,
  newIntervalMultiplier: 0.0,
  maximumIntervalDays: 36500,
  nextDayStartsAtHour: 4,
  learnAheadLimitMinutes: 20
};

function addMinutes(date, minutes) {
  return new Date(date.getTime() + minutes * 60 * 1000);
}

function addDays(date, days) {
  return new Date(date.getTime() + days * 24 * 60 * 60 * 1000);
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function getNextDayBoundary(now = new Date()) {
  const boundary = new Date(now);
  boundary.setHours(SUINDA_SCHEDULER_CONFIG.nextDayStartsAtHour, 0, 0, 0);

  if (now >= boundary) {
    boundary.setDate(boundary.getDate() + 1);
  }

  return boundary;
}

function crossesDayBoundary(now, futureDate) {
  return futureDate > getNextDayBoundary(now);
}

function scheduleMinuteDelayRespectingDayBoundary(now, minutes) {
  const future = addMinutes(now, minutes);

  if (crossesDayBoundary(now, future)) {
    return getNextDayBoundary(now);
  }

  return future;
}

function parseDurationMinutes(value, fallbackMinutes = 1) {
  const text = String(value || "").trim().toLowerCase().replace(",", ".");
  const match = text.match(/^(\d+(?:\.\d+)?)([mhd]?)$/);

  if (!match) return fallbackMinutes;

  const amount = Number(match[1]);
  const unit = match[2] || "m";

  if (unit === "h") return Math.max(1, Math.round(amount * 60));
  if (unit === "d") return Math.max(1, Math.round(amount * 24 * 60));
  return Math.max(1, Math.round(amount));
}

function parseLearningSteps(value, fallbackSteps) {
  const steps = String(value || "")
    .split(/\s+/)
    .map(step => parseDurationMinutes(step, 0))
    .filter(step => step > 0);

  return steps.length ? steps : fallbackSteps;
}

function getSchedulerOptions(deckId = null) {
  const deckOptions = typeof getDeckOptionsForDeck === "function"
    ? getDeckOptionsForDeck(deckId)
    : {};

  return {
    ...SUINDA_SCHEDULER_CONFIG,
    learningStepsMinutes: parseLearningSteps(
      deckOptions.learningSteps,
      SUINDA_SCHEDULER_CONFIG.learningStepsMinutes
    ),
    graduatingIntervalDays: Math.max(
      1,
      Number(deckOptions.graduatingIntervalDays ?? SUINDA_SCHEDULER_CONFIG.graduatingIntervalDays)
    ),
    easyIntervalDays: Math.max(
      1,
      Number(deckOptions.easyIntervalDays ?? SUINDA_SCHEDULER_CONFIG.easyIntervalDays)
    )
  };
}

function getDefaultCardProgress(userId, cardId) {
  return {
    userId,
    cardId,
    state: "new",
    stepIndex: 0,
    dueAt: null,
    intervalDays: 0,
    easeFactor: SUINDA_SCHEDULER_CONFIG.startingEase,
    lapses: 0,
    reps: 0,
    lastReviewedAt: null
  };
}

function scheduleLearning(progress, rating, now = new Date(), deckId = null) {
  const options = getSchedulerOptions(deckId);
  const steps = options.learningStepsMinutes;
  const currentStep = progress.stepIndex ?? 0;

  if (rating === "again") {
    progress.state = "learning";
    progress.stepIndex = 0;
    progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(now, steps[0]).toISOString();
    return progress;
  }

  if (rating === "hard") {
    progress.state = "learning";

    if (steps.length === 1) {
      const hardDelay = Math.round(steps[0] * 1.5);
      progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(now, hardDelay).toISOString();
      progress.stepIndex = 0;
      return progress;
    }

    if (currentStep === 0) {
      const avg = Math.round((steps[0] + steps[1]) / 2);
      progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(now, avg).toISOString();
      progress.stepIndex = 0;
      return progress;
    }

    progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(now, steps[currentStep]).toISOString();
    return progress;
  }

  if (rating === "good") {
    const nextStep = currentStep + 1;

    if (nextStep < steps.length) {
      progress.state = "learning";
      progress.stepIndex = nextStep;
      progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(now, steps[nextStep]).toISOString();
      return progress;
    }

    progress.state = "review";
    progress.stepIndex = 0;
    progress.intervalDays = options.graduatingIntervalDays;
    progress.dueAt = addDays(now, progress.intervalDays).toISOString();
    return progress;
  }

  if (rating === "easy") {
    progress.state = "review";
    progress.stepIndex = 0;
    progress.intervalDays = options.easyIntervalDays;
    progress.dueAt = addDays(now, progress.intervalDays).toISOString();
    return progress;
  }

  return progress;
}

function scheduleRelearning(progress, rating, now = new Date(), deckId = null) {
  const options = getSchedulerOptions(deckId);
  const steps = SUINDA_SCHEDULER_CONFIG.relearningStepsMinutes;
  const currentStep = progress.stepIndex ?? 0;

  if (rating === "again") {
    progress.state = "relearning";
    progress.stepIndex = 0;
    progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(now, steps[0]).toISOString();
    return progress;
  }

  if (rating === "hard") {
    progress.state = "relearning";
    progress.stepIndex = currentStep;
    progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(now, steps[currentStep]).toISOString();
    return progress;
  }

  if (rating === "good") {
    const nextStep = currentStep + 1;

    if (nextStep < steps.length) {
      progress.state = "relearning";
      progress.stepIndex = nextStep;
      progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(now, steps[nextStep]).toISOString();
      return progress;
    }

    progress.state = "review";
    progress.stepIndex = 0;
    progress.intervalDays = Math.max(
      SUINDA_SCHEDULER_CONFIG.minimumIntervalDays,
      progress.intervalDays || 1
    );
    progress.dueAt = addDays(now, progress.intervalDays).toISOString();
    return progress;
  }

  if (rating === "easy") {
    progress.state = "review";
    progress.stepIndex = 0;
    progress.intervalDays = Math.max(
      options.easyIntervalDays,
      progress.intervalDays || 1
    );
    progress.dueAt = addDays(now, progress.intervalDays).toISOString();
    return progress;
  }

  return progress;
}

function scheduleReview(progress, rating, now = new Date()) {
  const currentInterval = Math.max(1, progress.intervalDays || 1);
  const currentEase = progress.easeFactor || SUINDA_SCHEDULER_CONFIG.startingEase;

  if (rating === "again") {
    progress.state = "relearning";
    progress.stepIndex = 0;
    progress.lapses = (progress.lapses || 0) + 1;

    const newInterval = Math.max(
      SUINDA_SCHEDULER_CONFIG.minimumIntervalDays,
      Math.round(currentInterval * SUINDA_SCHEDULER_CONFIG.newIntervalMultiplier)
    );

    progress.intervalDays = newInterval;
    progress.easeFactor = Math.max(1.3, currentEase - 0.2);
    progress.dueAt = scheduleMinuteDelayRespectingDayBoundary(
      now,
      SUINDA_SCHEDULER_CONFIG.relearningStepsMinutes[0]
    ).toISOString();
    return progress;
  }

  if (rating === "hard") {
    progress.state = "review";
    progress.intervalDays = clamp(
      Math.round(currentInterval * SUINDA_SCHEDULER_CONFIG.hardIntervalMultiplier),
      1,
      SUINDA_SCHEDULER_CONFIG.maximumIntervalDays
    );
    progress.easeFactor = Math.max(1.3, currentEase - 0.15);
    progress.dueAt = addDays(now, progress.intervalDays).toISOString();
    return progress;
  }

  if (rating === "good") {
    progress.state = "review";
    progress.intervalDays = clamp(
      Math.round(currentInterval * currentEase),
      1,
      SUINDA_SCHEDULER_CONFIG.maximumIntervalDays
    );
    progress.easeFactor = currentEase;
    progress.dueAt = addDays(now, progress.intervalDays).toISOString();
    return progress;
  }

  if (rating === "easy") {
    progress.state = "review";
    progress.intervalDays = clamp(
      Math.round(currentInterval * currentEase * SUINDA_SCHEDULER_CONFIG.easyBonus),
      1,
      SUINDA_SCHEDULER_CONFIG.maximumIntervalDays
    );
    progress.easeFactor = currentEase + 0.15;
    progress.dueAt = addDays(now, progress.intervalDays).toISOString();
    return progress;
  }

  return progress;
}

function scheduleCard(progress, rating, now = new Date(), deckId = null) {
  progress.reps = (progress.reps || 0) + 1;
  progress.lastReviewedAt = now.toISOString();
  progress.lastRating = rating;

  if (progress.state === "new" || progress.state === "learning") {
    return scheduleLearning(progress, rating, now, deckId);
  }

  if (progress.state === "relearning") {
    return scheduleRelearning(progress, rating, now, deckId);
  }

  if (progress.state === "review") {
    return scheduleReview(progress, rating, now);
  }

  return progress;
}

function formatIntervalFromDueAt(dueAt, now = new Date()) {
  if (!dueAt) return "-";

  const diffMs = new Date(dueAt).getTime() - now.getTime();
  const diffSeconds = Math.max(0, Math.round(diffMs / 1000));

  if (diffSeconds < 60) return `${diffSeconds}s`;

  const diffMinutes = Math.round(diffSeconds / 60);
  if (diffMinutes < 60) return `${diffMinutes}m`;

  const diffHours = Math.round(diffMinutes / 60);
  if (diffHours < 24) return `${diffHours}h`;

  const diffDays = Math.round(diffHours / 24);
  return `${diffDays}d`;
}

function getPreviewForRating(progress, rating, now = new Date(), deckId = null) {
  const cloned = JSON.parse(JSON.stringify(progress));
  const scheduled = scheduleCard(cloned, rating, now, deckId);
  return formatIntervalFromDueAt(scheduled.dueAt, now);
}
