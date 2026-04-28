function browserEscape(value) {
  return String(value || "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function browserPlainText(value) {
  const raw = String(value || "");
  if (!raw) return "";

  const withBreaks = raw
    .replace(/\[sound:[^\]]+\]/gi, "")
    .replace(/<\s*br\s*\/?>/gi, "\n")
    .replace(/<\/(div|p|li|h[1-6])>/gi, "\n")
    .replace(/<li[^>]*>/gi, "- ");

  const element = document.createElement("div");
  element.innerHTML = withBreaks;

  return (element.textContent || element.innerText || withBreaks)
    .replace(/\u00a0/g, " ")
    .replace(/[ \t]+\n/g, "\n")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}

function getCardStateLabel(user, card) {
  if (!user || typeof getOrCreateCardProgress !== "function") return "Novo";

  const progress = getOrCreateCardProgress(user.id, card.id);
  if (!progress || progress.state === "new") return "Novo";
  if (progress.state === "learning") return "Aprendendo";
  if (progress.state === "review") return "Revisao";
  if (progress.state === "suspended") return "Suspenso";
  if (progress.state === "buried") return "Enterrado";
  return progress.state;
}

async function loadBrowserData() {
  const decks = await loadDecksFromApi();
  await Promise.all(decks.map(deck => loadCardsFromApi(deck.id, { includeMedia: false })));
  const activeDeckIds = new Set(decks.map(deck => Number(deck.id)));
  replaceArrayContent(
    mockCards,
    mockCards.filter(card => activeDeckIds.has(Number(card.deckId)))
  );
  saveToStorage("suinda_local_cards", mockCards);
  return decks;
}

function renderBrowserRows(decks) {
  const rows = document.getElementById("browserRows");
  const count = document.getElementById("browserCount");
  const search = document.getElementById("browserSearch")?.value.toLowerCase().trim() || "";
  const deckFilter = document.getElementById("browserDeckFilter")?.value || "";
  const user = getCurrentUser();

  const visibleCards = mockCards.filter(card => {
    const deck = decks.find(item => item.id === card.deckId);
    if (!deck) return false;
    const haystack = `${browserPlainText(card.question)} ${browserPlainText(card.answer)} ${deck?.title || ""}`.toLowerCase();
    const scopedDeckIds = deckFilter && typeof getDeckScopeDeckIds === "function"
      ? getDeckScopeDeckIds(Number(deckFilter)).map(String)
      : [deckFilter];
    return (!search || haystack.includes(search)) &&
      (!deckFilter || scopedDeckIds.includes(String(card.deckId)));
  });

  if (count) {
    count.textContent = `${visibleCards.length} cartoes visiveis`;
  }

  rows.innerHTML = visibleCards.map(card => {
    const deck = decks.find(item => item.id === card.deckId);
    return `
      <article class="browser-row" data-card-id="${card.id}">
        <span>${browserEscape(browserPlainText(card.questionHtml || card.question))}</span>
        <span>${browserEscape(card.cardType || "basico")}</span>
        <span>${browserEscape(getCardStateLabel(user, card))}</span>
        <span>${browserEscape(deck?.title || "Sem baralho")}</span>
      </article>
    `;
  }).join("") || `<p class="muted-line">Nenhum cartao encontrado.</p>`;

  rows.querySelectorAll("[data-card-id]").forEach(row => {
    row.addEventListener("click", () => {
      const card = mockCards.find(item => Number(item.id) === Number(row.dataset.cardId));
      if (card) openBrowserCardEditor(card, decks);
    });
  });
}

let browserCurrentCard = null;
let browserDecksCache = [];

async function loadFullBrowserCard(card) {
  if (!card?.id || typeof apiGetCard !== "function") {
    return card;
  }

  try {
    const fullCard = await apiGetCard(card.id);
    if (fullCard) {
      Object.assign(card, fullCard);
      const index = mockCards.findIndex(item => Number(item.id) === Number(card.id));
      if (index >= 0) {
        mockCards[index] = { ...mockCards[index], ...fullCard };
      }
    }
  } catch (error) {
    console.warn("Nao foi possivel carregar midias do cartao.", error);
  }

  return card;
}

function renderBrowserDeckOptions(decks, selectedDeckId) {
  const deckSelect = document.getElementById("browserCardDeck");
  if (!deckSelect) return;

  deckSelect.innerHTML = decks.map(deck => (
    `<option value="${deck.id}">${browserEscape(deck.title)}</option>`
  )).join("");
  deckSelect.value = String(selectedDeckId || decks[0]?.id || "");
}

function openBrowserCardEditor(card, decks) {
  browserCurrentCard = card;
  browserDecksCache = decks;

  const dialog = document.getElementById("browserCardDialog");
  const hint = document.getElementById("browserCardDeckHint");
  const deck = decks.find(item => Number(item.id) === Number(card.deckId));

  renderBrowserDeckOptions(decks, card.deckId);

  document.getElementById("browserCardType").value = card.cardType || "basic";
  document.getElementById("browserCardQuestion").value = browserPlainText(card.questionHtml || card.question);
  document.getElementById("browserCardAnswer").value = browserPlainText(card.answerHtml || card.answer);

  if (hint) {
    hint.textContent = deck?.title || "Sem baralho";
  }

  dialog?.showModal();
  setTimeout(() => document.getElementById("browserCardQuestion")?.focus(), 50);
}

function closeBrowserCardEditor() {
  document.getElementById("browserCardDialog")?.close();
  browserCurrentCard = null;
}

function removeLocalCard(cardId) {
  const index = mockCards.findIndex(card => Number(card.id) === Number(cardId));
  if (index >= 0) {
    mockCards.splice(index, 1);
  }

  saveToStorage("suinda_local_cards", mockCards);

  if (typeof getAllCardProgress === "function" && typeof saveAllCardProgress === "function") {
    saveAllCardProgress(
      getAllCardProgress().filter(progress => Number(progress.cardId) !== Number(cardId))
    );
  }
}

async function saveBrowserCard(decks) {
  if (!browserCurrentCard) return;

  const deckId = Number(document.getElementById("browserCardDeck")?.value || browserCurrentCard.deckId);
  const question = document.getElementById("browserCardQuestion")?.value.trim() || "";
  const answer = document.getElementById("browserCardAnswer")?.value.trim() || "";
  const cardType = document.getElementById("browserCardType")?.value || "basic";

  if (!deckId || !question || !answer) {
    showSuindaToast("Informe frente, verso e baralho.", "error");
    return;
  }

  const nextCard = {
    ...browserCurrentCard,
    deckId,
    question,
    answer,
    questionHtml: null,
    answerHtml: null,
    cardType
  };

  try {
    const updated = await apiUpdateCard(browserCurrentCard.id, nextCard);
    Object.assign(browserCurrentCard, updated);
  } catch (error) {
    Object.assign(browserCurrentCard, nextCard);
    saveToStorage("suinda_local_cards", mockCards);
  }

  closeBrowserCardEditor();
  renderBrowserRows(decks);
}

async function deleteBrowserCard(decks) {
  if (!browserCurrentCard) return;
  const cardId = browserCurrentCard.id;
  const confirmed = await showSuindaConfirm({
    title: "Excluir nota",
    message: "Excluir esta nota e seus dados de estudo?",
    confirmText: "Excluir",
    danger: true
  });
  if (!confirmed) return;

  try {
    await apiDeleteCard(cardId);
  } catch (error) {
    // Mantem a remocao local para uso offline.
  }

  removeLocalCard(cardId);
  closeBrowserCardEditor();
  renderBrowserRows(decks);
}

function getBrowserEditorDraft() {
  if (!browserCurrentCard) return null;

  const deckId = Number(document.getElementById("browserCardDeck")?.value || browserCurrentCard.deckId);
  const deck = browserDecksCache.find(item => Number(item.id) === deckId);

  return {
    ...browserCurrentCard,
    deckId,
    deckTitle: deck?.title || "Sem baralho",
    cardType: document.getElementById("browserCardType")?.value || browserCurrentCard.cardType || "basic",
    question: document.getElementById("browserCardQuestion")?.value.trim() || "",
    answer: document.getElementById("browserCardAnswer")?.value.trim() || "",
    imageData: browserCurrentCard.imageData || null,
    audioData: browserCurrentCard.audioData || null,
    occlusionMasks: browserCurrentCard.occlusionMasks || []
  };
}

function renderBrowserPreviewMedia(card, showAnswer) {
  const media = document.getElementById("browserPreviewMedia");
  if (!media) return;

  media.innerHTML = "";
  if (!card?.imageData) return;

  const masks = card.cardType === "occlusion" && !showAnswer ? (card.occlusionMasks || []) : [];
  media.innerHTML = `
    <div class="occlusion-frame">
      <img src="${card.imageData}" alt="Imagem do cartao" />
      ${masks.map(mask => `
        <span class="occlusion-mask" style="
          left: ${Number(mask.x) || 0}%;
          top: ${Number(mask.y) || 0}%;
          width: ${Number(mask.width) || 0}%;
          height: ${Number(mask.height) || 0}%;
        "></span>
      `).join("")}
    </div>
  `;
}

function renderBrowserPreview(showAnswer = false) {
  const draft = getBrowserEditorDraft();
  if (!draft) return;

  const question = document.getElementById("browserPreviewQuestion");
  const answer = document.getElementById("browserPreviewAnswer");
  const answerBlock = document.getElementById("browserPreviewAnswerBlock");
  const showAnswerBtn = document.getElementById("browserPreviewShowAnswerBtn");
  const answerActions = document.getElementById("browserPreviewAnswerActions");
  const audioBtn = document.getElementById("browserPreviewAudioBtn");
  const audioPanel = document.getElementById("browserPreviewAudioPanel");
  const audio = document.getElementById("browserPreviewAudio");
  const hint = document.getElementById("browserPreviewDeckHint");

  if (question) question.textContent = draft.question;
  if (answer) answer.textContent = draft.answer;
  if (hint) hint.textContent = draft.deckTitle;

  answerBlock?.classList.toggle("hidden", !showAnswer);
  showAnswerBtn?.classList.toggle("hidden", showAnswer);
  answerActions?.classList.toggle("hidden", !showAnswer);
  audioBtn?.classList.toggle("hidden", !draft.audioData);
  audioPanel?.classList.toggle("hidden", !draft.audioData);
  if (audio) {
    audio.src = draft.audioData || "";
  }

  renderBrowserPreviewMedia(draft, showAnswer);
}

async function openBrowserPreview() {
  if (browserCurrentCard) {
    await loadFullBrowserCard(browserCurrentCard);
  }

  const draft = getBrowserEditorDraft();
  if (!draft) return;

  if (!draft.question || !draft.answer) {
    showSuindaToast("Informe frente e verso para visualizar.", "error");
    return;
  }

  renderBrowserPreview(false);
  document.getElementById("browserCardMenu")?.classList.remove("open");
  document.getElementById("browserPreviewDialog")?.showModal();
}

function closeBrowserPreview() {
  document.getElementById("browserPreviewDialog")?.close();
}

function playBrowserPreviewAudio() {
  const draft = getBrowserEditorDraft();
  if (!draft?.audioData) return;
  const audio = document.getElementById("browserPreviewAudio");
  if (audio) {
    audio.currentTime = 0;
    audio.play();
    return;
  }

  new Audio(draft.audioData).play();
}

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();
  const decks = await loadBrowserData();
  const deckFilter = document.getElementById("browserDeckFilter");
  const search = document.getElementById("browserSearch");

  if (deckFilter) {
    deckFilter.innerHTML = `<option value="">Todos os baralhos</option>` + decks.map(deck => (
      `<option value="${deck.id}">${browserEscape(deck.title)}</option>`
    )).join("");
    const initialDeckId = new URLSearchParams(window.location.search).get("deckId");
    if (initialDeckId) {
      deckFilter.value = initialDeckId;
    }
    deckFilter.addEventListener("change", () => renderBrowserRows(decks));
  }

  search?.addEventListener("input", () => renderBrowserRows(decks));
  document.getElementById("browserSearchBtn")?.addEventListener("click", () => search?.focus());
  document.getElementById("browserCardCancelBtn")?.addEventListener("click", closeBrowserCardEditor);
  document.getElementById("browserCardCloseMenuBtn")?.addEventListener("click", closeBrowserCardEditor);
  document.getElementById("browserCardSaveBtn")?.addEventListener("click", () => saveBrowserCard(browserDecksCache));
  document.getElementById("browserCardDeleteBtn")?.addEventListener("click", () => deleteBrowserCard(browserDecksCache));
  document.getElementById("browserCardPreviewOpenBtn")?.addEventListener("click", openBrowserPreview);
  document.getElementById("browserCardMenuPreviewBtn")?.addEventListener("click", openBrowserPreview);
  document.getElementById("browserPreviewCloseBtn")?.addEventListener("click", closeBrowserPreview);
  document.getElementById("browserPreviewShowAnswerBtn")?.addEventListener("click", () => renderBrowserPreview(true));
  document.getElementById("browserPreviewAudioBtn")?.addEventListener("click", playBrowserPreviewAudio);
  document.getElementById("browserCardPreviewBtn")?.addEventListener("click", () => {
    if (!browserCurrentCard) return;
    const question = document.getElementById("browserCardQuestion")?.value.trim() || "";
    const answer = document.getElementById("browserCardAnswer")?.value.trim() || "";
    showSuindaConfirm({
      title: "Pre-visualização",
      message: `${question}\n\n---\n\n${answer}`,
      confirmText: "Fechar",
      cancelText: "",
    });
  });
  renderBrowserRows(decks);
});
