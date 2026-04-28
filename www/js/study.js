function getDeckIdFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return Number(params.get("id"));
}

function launchConfettiBurst() {
  const container = document.getElementById("confettiContainer");
  if (!container) return;

  container.innerHTML = "";

  const colors = [
    "#1f4d5c",
    "#2b6679",
    "#d9a63a",
    "#2f7d4a",
    "#ffffff",
    "#f2d27a"
  ];

  const totalPieces = 70;

  for (let i = 0; i < totalPieces; i++) {
    const piece = document.createElement("span");
    piece.className = "confetti-piece";

    const x = (Math.random() * 360) - 180;
    const y = 180 + (Math.random() * 320);
    const rotation = (Math.random() * 1080 - 540) + "deg";
    const duration = (1.6 + Math.random() * 1.4) + "s";
    const sizeW = 8 + Math.random() * 8;
    const sizeH = 12 + Math.random() * 10;
    const offsetX = (Math.random() * 120) - 60;

    piece.style.left = `calc(50% + ${offsetX}px)`;
    piece.style.width = `${sizeW}px`;
    piece.style.height = `${sizeH}px`;
    piece.style.background = colors[Math.floor(Math.random() * colors.length)];
    piece.style.setProperty("--x", `${x}px`);
    piece.style.setProperty("--y", `${y}px`);
    piece.style.setProperty("--r", rotation);
    piece.style.setProperty("--duration", duration);

    container.appendChild(piece);

    setTimeout(() => {
      piece.remove();
    }, 3200);
  }
}

function setCardContent(element, plainText, html) {
  if (!element) return;

  if (html) {
    element.innerHTML = String(html).replace(/\[sound:[^\]]+\]/gi, "");
    return;
  }

  element.textContent = String(plainText || "").replace(/\[sound:[^\]]+\]/gi, "").trim();
}

async function startStudyPage() {
  requireAuth();

  const deckId = getDeckIdFromUrl();
  const decks = await loadDecksFromApi();
  await loadDeckDetailFromApi(deckId);
  const scopeDeckIds = typeof getDeckScopeDeckIds === "function"
    ? getDeckScopeDeckIds(deckId)
    : [deckId];
  await Promise.all(scopeDeckIds.map(scopedDeckId => loadCardsFromApi(scopedDeckId, { includeMedia: false })));
  await loadCardProgressFromApi();

  const deck = decks.find(item => Number(item.id) === Number(deckId)) ||
    mockDecks.find(item => Number(item.id) === Number(deckId));
  const user = getCurrentUser();

  if (!deck) {
    showSuindaToast("Baralho não encontrado.", "error");
    window.location.href = "decks.html";
    return;
  }

  const state = {
    deck,
    currentCard: null,
    wrong: 0,
    hard: 0,
    easy: 0,
    veryEasy: 0,
    answers: [],
    startedAt: Date.now(),
    lastAnswerAt: Date.now(),
    answeredCardIdsInThisSession: []
  };

  const deckTitle = document.getElementById("studyDeckTitle");
  const progress = document.getElementById("studyProgress");
  const progressFill = document.getElementById("studyProgressFill");
  const questionText = document.getElementById("questionText");
  const answerText = document.getElementById("answerText");
  const answerBox = document.getElementById("answerBox");
  const studyMedia = document.getElementById("studyMedia");
  const answerMedia = document.getElementById("answerMedia");
  const showAnswerBtn = document.getElementById("showAnswerBtn");
  const speakQuestionBtn = document.getElementById("speakQuestionBtn");
  const playAudioBtn = document.getElementById("playAudioBtn");
  const answerActions = document.getElementById("answerActions");

  const wrongBtn = document.getElementById("wrongBtn");
  const hardBtn = document.getElementById("hardBtn");
  const easyBtn = document.getElementById("easyBtn");
  const veryEasyBtn = document.getElementById("veryEasyBtn");

  const wrongBtnTime = document.getElementById("wrongBtnTime");
  const hardBtnTime = document.getElementById("hardBtnTime");
  const easyBtnTime = document.getElementById("easyBtnTime");
  const veryEasyBtnTime = document.getElementById("veryEasyBtnTime");

  const studyNewCount = document.getElementById("studyNewCount");
  const studyLearningCount = document.getElementById("studyLearningCount");
  const studyReviewCount = document.getElementById("studyReviewCount");
  const studyDeckOptionsLink = document.getElementById("studyDeckOptionsLink");

  deckTitle.textContent = deck.title;
  if (studyDeckOptionsLink) {
    studyDeckOptionsLink.href = `deck-options.html?id=${deck.id}`;
  }

  function updateAnswerButtonTimes() {
    if (!state.currentCard) return;

    const progressData = getOrCreateCardProgress(user.id, state.currentCard.id);
    const now = new Date();

    wrongBtnTime.textContent = getPreviewForRating(progressData, "again", now, state.currentCard.deckId);
    hardBtnTime.textContent = getPreviewForRating(progressData, "hard", now, state.currentCard.deckId);
    easyBtnTime.textContent = getPreviewForRating(progressData, "good", now, state.currentCard.deckId);
    veryEasyBtnTime.textContent = getPreviewForRating(progressData, "easy", now, state.currentCard.deckId);
  }

  function updateStudyCounts() {
    const counts = getStudyCountsForDeck(user.id, deckId, new Date());

    if (studyNewCount) {
      studyNewCount.textContent = counts.new;
    }

    if (studyLearningCount) {
      studyLearningCount.textContent = counts.learning;
    }

    if (studyReviewCount) {
      studyReviewCount.textContent = counts.review;
    }
  }

  function getNextAvailableCard() {
    const now = new Date();
    const dueItems = getDueCardsForDeck(user.id, deckId, now);

    if (dueItems.length === 0) {
      return null;
    }

    return dueItems[0].card;
  }

  function renderCurrentCard() {
    if (!state.currentCard) {
      finishStudy();
      return;
    }

    const answeredCount = state.answers.length + 1;
    const totalVisible = answeredCount + Math.max(
      0,
      getStudyCountsForDeck(user.id, deckId, new Date()).new +
      getStudyCountsForDeck(user.id, deckId, new Date()).learning +
      getStudyCountsForDeck(user.id, deckId, new Date()).review - 1
    );

    let percent = 0;
    if (totalVisible > 0) {
      percent = Math.min(100, Math.round((answeredCount / totalVisible) * 100));
    }

    progress.textContent = `Card em estudo`;

    if (progressFill) {
      progressFill.style.width = `${percent}%`;
    }

    setCardContent(questionText, state.currentCard.question, state.currentCard.questionHtml);
    setCardContent(answerText, state.currentCard.answer, state.currentCard.answerHtml);
    renderStudyMedia(false);

    answerBox.classList.add("hidden");
    answerActions.classList.add("hidden");
    showAnswerBtn.classList.remove("hidden");

    if (playAudioBtn) {
      playAudioBtn.classList.toggle("hidden", !state.currentCard.audioData);
    }

    updateAnswerButtonTimes();
    updateStudyCounts();
  }

  function renderMask(mask) {
    return `<span class="occlusion-mask" style="left:${mask.x}%;top:${mask.y}%;width:${mask.width}%;height:${mask.height}%;"></span>`;
  }

  function renderStudyMedia(showAnswer) {
    const card = state.currentCard;
    if (!card) return;

    if (studyMedia) {
      studyMedia.innerHTML = "";
    }
    if (answerMedia) {
      answerMedia.innerHTML = "";
    }

    if (!card.imageData) return;

    const masks = card.cardType === "image_occlusion" && !showAnswer
      ? (card.occlusionMasks || [])
      : [];
    const mediaHtml = `
      <div class="occlusion-frame">
        <img src="${card.imageData}" alt="Imagem do cartão" />
        ${masks.map(renderMask).join("")}
      </div>
    `;

    if (showAnswer && answerMedia) {
      answerMedia.innerHTML = mediaHtml;
    } else if (studyMedia) {
      studyMedia.innerHTML = mediaHtml;
    }
  }

  function speakCurrentQuestion() {
    if (!state.currentCard) return;

    if (state.currentCard.audioData) {
      playCurrentAudio();
      return;
    }

    if (!("speechSynthesis" in window)) return;
    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(state.currentCard.question);
    utterance.lang = /^[\x00-\x7F]+$/.test(state.currentCard.question || "") ? "en-US" : "pt-BR";
    window.speechSynthesis.speak(utterance);
  }

  function playCurrentAudio() {
    if (!state.currentCard?.audioData) return;
    new Audio(state.currentCard.audioData).play();
  }

  function closeStudyMenu() {
    document.getElementById("studyContextMenu")?.classList.remove("open");
  }

  function saveCardsLocally() {
    saveToStorage("suinda_local_cards", mockCards);
  }

  function replaceCurrentCard(updatedCard) {
    const index = mockCards.findIndex(card => Number(card.id) === Number(updatedCard.id));

    if (index >= 0) {
      mockCards[index] = { ...mockCards[index], ...updatedCard };
    }

    state.currentCard = { ...state.currentCard, ...updatedCard };
    saveCardsLocally();
  }

  async function persistCurrentCard(changes) {
    if (!state.currentCard) return;

    const updatedCard = { ...state.currentCard, ...changes };

    try {
      const savedCard = await apiUpdateCard(updatedCard.id, {
        question: updatedCard.question,
        answer: updatedCard.answer,
        questionHtml: updatedCard.questionHtml || null,
        answerHtml: updatedCard.answerHtml || null,
        cardType: updatedCard.cardType || "basic",
        imageData: updatedCard.imageData || null,
        audioData: updatedCard.audioData || null,
        occlusionMasks: updatedCard.occlusionMasks || []
      });
      replaceCurrentCard({ ...savedCard, tags: updatedCard.tags });
    } catch (error) {
      replaceCurrentCard(updatedCard);
    }

    renderCurrentCard();
  }

  async function editCurrentNote() {
    if (!state.currentCard) return;

    const question = await showSuindaPrompt({
      title: "Editar frente",
      label: "Frente do cartao",
      value: state.currentCard.question || "",
      multiline: true
    });
    if (question === null) return;

    const answer = await showSuindaPrompt({
      title: "Editar verso",
      label: "Verso do cartao",
      value: state.currentCard.answer || "",
      multiline: true
    });
    if (answer === null) return;

    await persistCurrentCard({
      question: question.trim(),
      answer: answer.trim(),
      questionHtml: null,
      answerHtml: null
    });
  }

  async function editCurrentTags() {
    if (!state.currentCard) return;

    const tags = await showSuindaPrompt({
      title: "Editar etiquetas",
      label: "Etiquetas separadas por espaco",
      value: state.currentCard.tags || ""
    });
    if (tags === null) return;

    await persistCurrentCard({ tags: tags.trim() });
  }

  function buryCurrentCard() {
    if (!state.currentCard) return;

    const progressData = getOrCreateCardProgress(user.id, state.currentCard.id);
    progressData.previousState = progressData.state === "buried"
      ? progressData.previousState
      : progressData.state;
    progressData.state = "buried";
    progressData.dueAt = getNextDayBoundary(new Date()).toISOString();
    progressData.lastRating = "buried";
    upsertCardProgress(progressData);
    updateStudyCounts();
    loadNextCard();
  }

  function suspendCurrentCard() {
    if (!state.currentCard) return;

    const progressData = getOrCreateCardProgress(user.id, state.currentCard.id);
    progressData.previousState = progressData.state === "suspended"
      ? progressData.previousState
      : progressData.state;
    progressData.state = "suspended";
    progressData.dueAt = null;
    progressData.lastRating = "suspended";
    upsertCardProgress(progressData);
    updateStudyCounts();
    loadNextCard();
  }

  async function deleteCurrentNote() {
    if (!state.currentCard) return;

    const confirmed = await showSuindaConfirm({
      title: "Excluir nota",
      message: "Excluir este cartao e seus dados de estudo?",
      confirmText: "Excluir",
      danger: true
    });
    if (!confirmed) return;

    const cardId = state.currentCard.id;

    try {
      await apiDeleteCard(cardId);
    } catch (error) {
      // Student users may not be allowed to delete on the API yet; keep local UX working.
    }

    const remaining = mockCards.filter(card => Number(card.id) !== Number(cardId));
    replaceArrayContent(mockCards, remaining);
    saveCardsLocally();
    updateStudyCounts();
    loadNextCard();
  }

  function markCurrentNote() {
    if (!state.currentCard) return;

    const progressData = getOrCreateCardProgress(user.id, state.currentCard.id);
    progressData.marked = !progressData.marked;
    upsertCardProgress(progressData);
    showSuindaToast(progressData.marked ? "Cartao marcado." : "Marcacao removida.");
  }

  async function runStudyContextAction(action) {
    closeStudyMenu();

    if (action === "edit-note") {
      await editCurrentNote();
      return;
    }

    if (action === "edit-tags") {
      await editCurrentTags();
      return;
    }

    if (action === "bury-card") {
      buryCurrentCard();
      return;
    }

    if (action === "suspend-card") {
      suspendCurrentCard();
      return;
    }

    if (action === "delete-note") {
      await deleteCurrentNote();
      return;
    }

    if (action === "mark-note") {
      markCurrentNote();
    }
  }

  async function finishStudy() {
    const finishedAt = Date.now();
    const totalAnswered = state.answers.length;

    if (totalAnswered === 0) {
      showSuindaToast("Nenhum card disponível para estudar agora.");
      window.location.href = "deck-detail.html?id=" + deckId;
      return;
    }

    const durationInSeconds = Math.max(
      1,
      Math.round((finishedAt - state.startedAt) / 1000)
    );

    const averageSecondsPerCard = Math.max(
      1,
      Math.round(durationInSeconds / totalAnswered)
    );

    const result = {
      deckId: state.deck.id,
      deckTitle: state.deck.title,
      total: totalAnswered,
      wrong: state.wrong,
      hard: state.hard,
      easy: state.easy,
      veryEasy: state.veryEasy,
      answers: state.answers,
      createdAt: new Date().toISOString(),
      startedAt: new Date(state.startedAt).toISOString(),
      finishedAt: new Date(finishedAt).toISOString(),
      durationInSeconds,
      averageSecondsPerCard
    };

    await saveStudySession(result);
    saveToStorage("suinda_last_result", result);
    window.location.href = "result.html";
  }

  async function loadFullCurrentCard() {
    if (!state.currentCard?.id || typeof apiGetCard !== "function") {
      return;
    }

    const hasPlayableAudio = typeof state.currentCard.audioData === "string" &&
      state.currentCard.audioData.startsWith("data:audio/");

    if (hasPlayableAudio || (state.currentCard.imageData && !state.currentCard.audioData)) {
      return;
    }

    try {
      const fullCard = await apiGetCard(state.currentCard.id);
      if (fullCard) {
        const index = mockCards.findIndex(card => Number(card.id) === Number(fullCard.id));
        if (index >= 0) {
          mockCards[index] = { ...mockCards[index], ...fullCard };
          state.currentCard = mockCards[index];
        } else {
          state.currentCard = { ...state.currentCard, ...fullCard };
        }
      }
    } catch (error) {
      console.warn("Nao foi possivel carregar midias do cartao atual.", error);
    }
  }

  async function loadNextCard() {
    state.currentCard = getNextAvailableCard();

    if (!state.currentCard) {
      finishStudy();
      return;
    }

    await loadFullCurrentCard();
    renderCurrentCard();
  }

  function registerAnswer(type) {
    if (!state.currentCard) return;

    const currentCard = state.currentCard;

    const now = new Date();
    const answerTimestamp = now.getTime();
    const answerDurationInSeconds = Math.max(
      1,
      Math.round((answerTimestamp - state.lastAnswerAt) / 1000)
    );
    let progressData = getOrCreateCardProgress(user.id, currentCard.id);
    const wasNewCard = progressData.state === "new";

    if (progressData.state === "buried") {
      progressData.state = progressData.previousState || "review";
    }

    progressData = scheduleCard(progressData, type, now, currentCard.deckId);

    if (wasNewCard && !progressData.introducedAt) {
      progressData.introducedAt = now.toISOString();
    }

    upsertCardProgress(progressData);
    state.lastAnswerAt = answerTimestamp;

    if (typeof recordStudyAnswerActivity === "function") {
      recordStudyAnswerActivity({
        answeredAt: now.toISOString(),
        durationInSeconds: answerDurationInSeconds
      });
    }

    state.answers.push({
      cardId: currentCard.id,
      question: currentCard.question,
      answerType: type,
      nextState: progressData.state,
      dueAt: progressData.dueAt
    });

    state.answeredCardIdsInThisSession.push(currentCard.id);

    if (type === "again") state.wrong += 1;
    if (type === "hard") state.hard += 1;
    if (type === "good") state.easy += 1;
    if (type === "easy") state.veryEasy += 1;

    updateStudyCounts();
    loadNextCard();
  }

  showAnswerBtn.addEventListener("click", () => {
    answerBox.classList.remove("hidden");
    answerActions.classList.remove("hidden");
    showAnswerBtn.classList.add("hidden");
    renderStudyMedia(true);
  });

  speakQuestionBtn?.addEventListener("click", speakCurrentQuestion);
  playAudioBtn?.addEventListener("click", playCurrentAudio);

  wrongBtn.addEventListener("click", () => registerAnswer("again"));
  hardBtn.addEventListener("click", () => registerAnswer("hard"));
  easyBtn.addEventListener("click", () => registerAnswer("good"));
  veryEasyBtn.addEventListener("click", () => registerAnswer("easy"));

  document.querySelectorAll("[data-study-action]").forEach(button => {
    button.addEventListener("click", () => runStudyContextAction(button.dataset.studyAction));
  });

  updateStudyCounts();
  loadNextCard();
}

function startResultPage() {
  const resultDeckName = document.getElementById("resultDeckName");
  const resultSummary = document.getElementById("resultSummary");
  const resultStats = document.getElementById("resultStats");
  const studyAgainBtn = document.getElementById("studyAgainBtn");

  if (!resultDeckName || !resultSummary || !resultStats) return;

  const result = getFromStorage("suinda_last_result");

  if (!result) {
    resultDeckName.textContent = "Nenhum resultado encontrado";
    resultSummary.textContent = "Faça uma sessão de estudo primeiro.";
    resultStats.innerHTML = `
      <div class="progress-box">
        <strong>0</strong>
        <span>Sessões encontradas</span>
      </div>
    `;
    return;
  }

  resultDeckName.textContent = result.deckTitle;
  resultSummary.textContent = `Você concluiu ${result.total} cards neste baralho.`;

  const todaySummary = getTodayStudySummary();

  resultStats.innerHTML = `
    <div class="progress-box">
      <strong>${result.total}</strong>
      <span>Total de cards</span>
    </div>
    <div class="progress-box">
      <strong>${result.wrong}</strong>
      <span>Errei</span>
    </div>
    <div class="progress-box">
      <strong>${result.hard}</strong>
      <span>Difícil</span>
    </div>
    <div class="progress-box">
      <strong>${result.easy}</strong>
      <span>Fácil</span>
    </div>
    <div class="progress-box">
      <strong>${result.veryEasy}</strong>
      <span>Muito fácil</span>
    </div>
    <div class="progress-box">
      <strong>${result.easy + result.veryEasy}</strong>
      <span>Respostas boas</span>
    </div>
    <div class="progress-box">
      <strong>${todaySummary.totalCards}</strong>
      <span>Cards estudados hoje</span>
    </div>
    <div class="progress-box">
      <strong>${formatSecondsToTime(todaySummary.totalStudyTimeInSeconds)}</strong>
      <span>Tempo estudado hoje</span>
    </div>
    <div class="progress-box">
      <strong>${formatSecondsToTime(todaySummary.averageSecondsPerCard)}</strong>
      <span>Média por card hoje</span>
    </div>
    <div class="progress-box">
      <strong>${formatSecondsToTime(result.durationInSeconds)}</strong>
      <span>Tempo desta sessão</span>
    </div>
  `;

  if (studyAgainBtn && result.deckId) {
    studyAgainBtn.href = `study.html?id=${result.deckId}`;
  }

  launchConfettiBurst();
}

document.addEventListener("DOMContentLoaded", () => {
  const path = window.location.pathname;

  if (path.includes("study.html")) {
    startStudyPage();
  }

  if (path.includes("result.html")) {
    startResultPage();
  }
});
