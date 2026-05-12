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

async function loadBrowserDecks() {
  return await loadDecksFromApi();
}

function dispatchBrowserDataReady(decks) {
  // Mantemos o cache local sincronizado com o set ativo de decks - filtra os
  // cards que sobraram em memoria mas cujos decks deixaram de existir.
  const activeDeckIds = new Set(decks.map(deck => Number(deck.id)));
  replaceArrayContent(
    mockCards,
    mockCards.filter(card => activeDeckIds.has(Number(card.deckId)))
  );
  saveToStorage("suinda_local_cards", mockCards);
}

// Dispara os GETs de cards por deck e chama onDeckLoaded a cada deck que
// completar. Renderizacao progressiva: o usuario ja ve os primeiros cards
// assim que o primeiro deck responde, sem precisar esperar o slowest deck.
function streamCardsByDeck(decks, onDeckLoaded) {
  return decks.map(deck => {
    return loadCardsFromApi(deck.id, { includeMedia: false })
      .then(() => onDeckLoaded?.(deck))
      .catch(error => {
        console.warn("Falha ao carregar cards do deck.", { deckId: deck.id, error });
      });
  });
}

function findDeckForCard(decks, card) {
  // Comparacao numerica para tolerar mockCards salvos em sessoes antigas
  // onde deckId podia ser string vs number.
  const cardDeckId = Number(card?.deckId);
  if (!Number.isFinite(cardDeckId)) return null;
  return decks.find(item => Number(item.id) === cardDeckId) || null;
}

function safeCardStateLabel(user, card) {
  try {
    return getCardStateLabel(user, card);
  } catch (error) {
    console.warn("Falha ao calcular o estado do cartao.", error);
    return "Novo";
  }
}

// Cap inicial de linhas renderizadas. innerHTML com 5k+ <article> trava o
// main thread; renderizamos as primeiras N e oferecemos um "Mostrar mais".
const BROWSER_ROWS_PAGE_SIZE = 200;
let browserRowsLimit = BROWSER_ROWS_PAGE_SIZE;

function resetBrowserRowsLimit() {
  browserRowsLimit = BROWSER_ROWS_PAGE_SIZE;
}

function renderBrowserRows(decks) {
  const rows = document.getElementById("browserRows");
  const count = document.getElementById("browserCount");
  if (!rows) return;

  const search = document.getElementById("browserSearch")?.value.toLowerCase().trim() || "";
  const deckFilter = document.getElementById("browserDeckFilter")?.value || "";
  const user = getCurrentUser();

  let visibleCards = [];
  try {
    visibleCards = mockCards.filter(card => {
      const deck = findDeckForCard(decks, card);
      if (!deck) return false;
      // browserPlainText e relativamente caro - so paga o custo se houver
      // termo de busca. Sem busca, pulamos a derivacao de haystack.
      if (search) {
        const haystack = `${browserPlainText(card.question)} ${browserPlainText(card.answer)} ${deck.title || ""}`.toLowerCase();
        if (!haystack.includes(search)) return false;
      }
      if (!deckFilter) return true;
      const scopedDeckIds = typeof getDeckScopeDeckIds === "function"
        ? getDeckScopeDeckIds(Number(deckFilter)).map(String)
        : [deckFilter];
      return scopedDeckIds.includes(String(card.deckId));
    });
  } catch (error) {
    console.error("Erro ao filtrar cartoes do navegador.", error);
    visibleCards = [];
  }

  const total = visibleCards.length;
  if (count) {
    count.textContent = `${total} cartoes visiveis`;
  }

  const limit = Math.max(BROWSER_ROWS_PAGE_SIZE, browserRowsLimit);
  const cardsToRender = visibleCards.slice(0, limit);
  const remaining = Math.max(0, total - cardsToRender.length);

  const rowsHtml = cardsToRender.map(card => {
    try {
      const deck = findDeckForCard(decks, card);
      const previewText = browserPlainText(card.questionHtml || card.question) || "(sem texto)";
      return `
        <article class="browser-row" data-card-id="${browserEscape(card.id)}">
          <span>${browserEscape(previewText)}</span>
          <span>${browserEscape(card.cardType || "basico")}</span>
          <span>${browserEscape(safeCardStateLabel(user, card))}</span>
          <span>${browserEscape(deck?.title || "Sem baralho")}</span>
        </article>
      `;
    } catch (error) {
      console.warn("Falha ao renderizar cartao no navegador.", { cardId: card?.id, error });
      return "";
    }
  }).join("");

  const showMoreHtml = remaining > 0
    ? `<button type="button" id="browserShowMoreBtn" class="browser-show-more">Mostrar mais ${remaining} cartoes</button>`
    : "";

  rows.innerHTML = (rowsHtml || `<p class="muted-line">Nenhum cartao encontrado.</p>`) + showMoreHtml;

  rows.querySelectorAll("[data-card-id]").forEach(row => {
    row.addEventListener("click", () => {
      const card = mockCards.find(item => Number(item.id) === Number(row.dataset.cardId));
      if (!card) return;
      Promise.resolve()
        .then(() => openBrowserCardEditor(card, decks))
        .catch(error => {
          console.error("Falha ao abrir o editor de cartao.", error);
          showSuindaToast("Nao foi possivel abrir o cartao.", "error");
        });
    });
  });

  document.getElementById("browserShowMoreBtn")?.addEventListener("click", () => {
    browserRowsLimit += BROWSER_ROWS_PAGE_SIZE;
    renderBrowserRows(decks);
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

function isBrowserOcclusionCard(card) {
  const type = String(card?.cardType || "").toLowerCase();
  return type === "image_occlusion" || type === "occlusion";
}

function buildOcclusionMasksHtml(masks, options = {}) {
  if (!Array.isArray(masks) || masks.length === 0) return "";
  const onlyTargets = Boolean(options.onlyTargets);
  return masks
    .filter(mask => !onlyTargets || mask.isTarget)
    .map(mask => {
      const x = Number(mask.x) || 0;
      const y = Number(mask.y) || 0;
      const w = Number(mask.width) || 0;
      const h = Number(mask.height) || 0;
      const targetClass = mask.isTarget ? " occlusion-mask-target" : "";
      return `<span class="occlusion-mask${targetClass}" style="left:${x}%;top:${y}%;width:${w}%;height:${h}%;"></span>`;
    })
    .join("");
}

function renderBrowserOcclusionPanel(card) {
  // Painel de visualizacao dedicado para notas de oclusao. Mostra apenas
  // leitura: a imagem original, mascara da pergunta, mascara da resposta e
  // o HTML renderizado da frente/verso. Nao toca em campos editaveis - assim
  // imageData/occlusionMasks ficam intactos no banco e o contenteditable nao
  // recebe blocos aninhados (que estavam causando regressao na listagem).
  const panel = document.getElementById("browserCardOcclusionPanel");
  if (!panel) return;

  if (!isBrowserOcclusionCard(card)) {
    panel.classList.add("hidden");
    panel.innerHTML = "";
    return;
  }

  const masks = Array.isArray(card?.occlusionMasks) ? card.occlusionMasks : [];
  const hasImage = Boolean(card?.imageData);
  const hasMasks = masks.length > 0;
  const hasTargets = masks.some(mask => mask.isTarget);

  const imageBlock = hasImage
    ? `
      <section class="occlusion-view-section">
        <h3>Imagem</h3>
        <div class="occlusion-frame occlusion-view-frame">
          <img src="${browserEscape(card.imageData)}" alt="Imagem do cartao de oclusao" />
        </div>
      </section>
    `
    : `
      <section class="occlusion-view-section">
        <h3>Imagem</h3>
        <p class="muted-line">Carregando imagem original...</p>
      </section>
    `;

  const questionMaskBlock = hasImage && hasMasks
    ? `
      <section class="occlusion-view-section">
        <h3>Mascara da pergunta</h3>
        <div class="occlusion-frame occlusion-view-frame">
          <img src="${browserEscape(card.imageData)}" alt="Mascara da pergunta" />
          ${buildOcclusionMasksHtml(masks)}
        </div>
      </section>
    `
    : "";

  const answerMaskBlock = hasImage && hasTargets
    ? `
      <section class="occlusion-view-section">
        <h3>Mascara da resposta</h3>
        <div class="occlusion-frame occlusion-view-frame">
          <img src="${browserEscape(card.imageData)}" alt="Mascara da resposta" />
          ${buildOcclusionMasksHtml(masks, { onlyTargets: true })}
        </div>
      </section>
    `
    : "";

  const frontHtml = card?.questionHtml ? sanitizeCardHtml(card.questionHtml) : "";
  const backHtml = card?.answerHtml ? sanitizeCardHtml(card.answerHtml) : "";
  const frontRendered = (frontHtml || browserEscape(card?.question || "")).trim();
  const backRendered = (backHtml || browserEscape(card?.answer || "")).trim();

  const frontBlock = frontRendered
    ? `
      <section class="occlusion-view-section">
        <h3>Frente renderizada</h3>
        <div class="occlusion-view-rendered">${frontRendered}</div>
      </section>
    `
    : "";

  const backBlock = backRendered
    ? `
      <section class="occlusion-view-section">
        <h3>Verso renderizado</h3>
        <div class="occlusion-view-rendered">${backRendered}</div>
      </section>
    `
    : "";

  panel.innerHTML = `
    <header class="occlusion-view-header">
      <h2>Conteudo da nota de oclusao</h2>
      <p class="muted-line occlusion-view-hint">Imagem, mascaras e campos brutos sao preservados automaticamente. A edicao avancada de mascaras nao e suportada nesta versao.</p>
    </header>
    ${imageBlock}
    ${questionMaskBlock}
    ${answerMaskBlock}
    ${frontBlock}
    ${backBlock}
  `;
  panel.classList.remove("hidden");
}

async function openBrowserCardEditor(card, decks) {
  browserCurrentCard = card;
  browserDecksCache = decks;

  const dialog = document.getElementById("browserCardDialog");
  const hint = document.getElementById("browserCardDeckHint");
  const deck = findDeckForCard(decks, card);

  renderBrowserDeckOptions(decks, card.deckId);

  const typeSelect = document.getElementById("browserCardType");
  if (typeSelect) typeSelect.value = card.cardType || "basic";

  const questionEditor = document.getElementById("browserCardQuestion");
  const answerEditor = document.getElementById("browserCardAnswer");

  // Editor rico: carrega apenas o HTML textual do card. Imagens/mascaras de
  // oclusao NAO sao injetadas aqui - elas ficam no painel dedicado abaixo
  // para nao misturar conteudo visual e contenteditable.
  if (questionEditor) {
    questionEditor.innerHTML = card.questionHtml || escapeHtmlForRichEditor(card.question || "");
  }
  if (answerEditor) {
    answerEditor.innerHTML = card.answerHtml || escapeHtmlForRichEditor(card.answer || "");
  }

  // Toolbar do editor rico. Isolada em try/catch para que uma falha no
  // editor nao impeca o usuario de visualizar/editar texto basico.
  const toolbarSlot = document.getElementById("browserCardToolbarSlot");
  try {
    if (toolbarSlot && typeof buildSuindaRichToolbar === "function" && typeof setupSuindaRichToolbar === "function") {
      toolbarSlot.innerHTML = buildSuindaRichToolbar();
      setupSuindaRichToolbar(dialog);
    }
  } catch (error) {
    console.warn("Falha ao inicializar a toolbar do editor rico.", error);
    if (toolbarSlot) toolbarSlot.innerHTML = "";
  }

  if (hint) {
    hint.textContent = deck?.title || "Sem baralho";
  }

  renderBrowserOcclusionPanel(card);

  dialog?.showModal();
  setTimeout(() => questionEditor?.focus(), 50);

  // Carrega imageData em background (a listagem usa includeMedia=false).
  // Falha aqui nao quebra o editor - so deixa o painel com placeholder.
  if (isBrowserOcclusionCard(card) && !card.imageData) {
    try {
      await loadFullBrowserCard(card);
      if (browserCurrentCard === card) {
        renderBrowserOcclusionPanel(card);
      }
    } catch (error) {
      console.warn("Nao foi possivel carregar a midia do cartao.", error);
    }
  }
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
  const questionHtml = sanitizeCardHtml(
    document.getElementById("browserCardQuestion")?.innerHTML || ""
  );
  const answerHtml = sanitizeCardHtml(
    document.getElementById("browserCardAnswer")?.innerHTML || ""
  );
  const question = htmlToPlainText(questionHtml);
  const answer = htmlToPlainText(answerHtml);
  const cardType = document.getElementById("browserCardType")?.value || browserCurrentCard.cardType || "basic";

  // Cartoes de oclusao podem ter "question"/"answer" textualmente vazios
  // (so imagem + mascaras), entao nao exigimos texto nesse caso.
  const isOcclusion = isBrowserOcclusionCard({ cardType });
  const hasVisual = Boolean(browserCurrentCard.imageData) ||
    (Array.isArray(browserCurrentCard.occlusionMasks) && browserCurrentCard.occlusionMasks.length > 0);

  if (!deckId || (!isOcclusion && (!question || !answer)) || (isOcclusion && !question && !answer && !hasVisual)) {
    showSuindaToast("Informe frente, verso e baralho.", "error");
    return;
  }

  const nextCard = {
    ...browserCurrentCard,
    deckId,
    question: question || browserCurrentCard.question || "",
    answer: answer || browserCurrentCard.answer || "",
    questionHtml: questionHtml || null,
    answerHtml: answerHtml || null,
    cardType,
    // Reenvia explicitamente os campos de midia / oclusao para o backend
    // nao re-gravar NULL em occlusion_masks (vide updateCard em App.php).
    imageData: browserCurrentCard.imageData ?? null,
    audioData: browserCurrentCard.audioData ?? null,
    occlusionMasks: Array.isArray(browserCurrentCard.occlusionMasks)
      ? browserCurrentCard.occlusionMasks
      : []
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

  const questionHtml = sanitizeCardHtml(
    document.getElementById("browserCardQuestion")?.innerHTML || ""
  );
  const answerHtml = sanitizeCardHtml(
    document.getElementById("browserCardAnswer")?.innerHTML || ""
  );

  return {
    ...browserCurrentCard,
    deckId,
    deckTitle: deck?.title || "Sem baralho",
    cardType: document.getElementById("browserCardType")?.value || browserCurrentCard.cardType || "basic",
    question: htmlToPlainText(questionHtml),
    answer: htmlToPlainText(answerHtml),
    questionHtml: questionHtml || null,
    answerHtml: answerHtml || null,
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

  const isOcclusion = isBrowserOcclusionCard(card);
  const masks = isOcclusion
    ? (card.occlusionMasks || []).filter(mask => !(showAnswer && mask.isTarget))
    : [];
  media.innerHTML = `
    <div class="occlusion-frame">
      <img src="${browserEscape(card.imageData)}" alt="Imagem do cartao" />
      ${masks.map(mask => `
        <span class="occlusion-mask${mask.isTarget ? " occlusion-mask-target" : ""}" style="
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

  // Renderiza HTML (sanitizado) para que midia inserida no editor (img,
  // audio, video) apareca no preview, e nao apenas o texto plano.
  if (question) {
    question.innerHTML = draft.questionHtml
      ? sanitizeCardHtml(draft.questionHtml)
      : escapeHtmlForRichEditor(draft.question || "");
  }
  if (answer) {
    answer.innerHTML = draft.answerHtml
      ? sanitizeCardHtml(draft.answerHtml)
      : escapeHtmlForRichEditor(draft.answer || "");
  }
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

  const isOcclusion = isBrowserOcclusionCard(draft);
  const hasVisual = Boolean(draft.imageData) || (Array.isArray(draft.occlusionMasks) && draft.occlusionMasks.length > 0);
  const missingText = !draft.question || !draft.answer;

  if (missingText && !(isOcclusion && hasVisual)) {
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

function renderBrowserLoadError(message) {
  const rows = document.getElementById("browserRows");
  if (!rows) return;
  rows.innerHTML = `<p class="muted-line error-line">${browserEscape(message || "Nao foi possivel carregar os cartoes.")}</p>`;
}

function attachBrowserEventListeners(decks) {
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
    deckFilter.addEventListener("change", () => {
      try {
        resetBrowserRowsLimit();
        renderBrowserRows(decks);
      } catch (error) { console.error("Falha ao filtrar cartoes.", error); }
    });
  }

  search?.addEventListener("input", () => {
    try {
      resetBrowserRowsLimit();
      renderBrowserRows(decks);
    } catch (error) { console.error("Falha ao pesquisar cartoes.", error); }
  });
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
}

function renderBrowserLoadingState() {
  const rows = document.getElementById("browserRows");
  if (!rows) return;
  rows.innerHTML = `<p class="muted-line">Carregando cartoes...</p>`;
}

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();
  console.time("browser-load-total");

  renderBrowserLoadingState();

  let decks = [];
  try {
    decks = await loadBrowserDecks();
  } catch (error) {
    console.error("Falha ao carregar dados do navegador.", error);
    renderBrowserLoadError("Nao foi possivel falar com o servidor. Verifique a conexao e recarregue a pagina.");
    if (typeof showSuindaToast === "function") {
      showSuindaToast("Falha ao carregar cartoes.", "error");
    }
    try { attachBrowserEventListeners(decks); } catch (_) {}
    return;
  }

  try {
    attachBrowserEventListeners(decks);
  } catch (error) {
    console.error("Falha ao registrar listeners do navegador.", error);
  }

  // Renderiza ja com lista vazia (mostra o estado "Nenhum cartao encontrado"
  // ou primeiros cards conforme chegarem). Importante: NAO esperamos o
  // Promise.all antes de chamar renderBrowserRows.
  try { renderBrowserRows(decks); } catch (error) { console.error(error); }

  // Disparo paralelo + renderizacao progressiva. Cada deck que termina
  // dispara um re-render incremental. Em decks Anki Image Occlusion (Anatomia)
  // o usuario ja ve a primeira parte da tabela em segundos em vez de esperar
  // a soma dos round-trips.
  let lastRenderAt = 0;
  let renderQueued = false;
  function scheduleProgressiveRender() {
    const now = performance.now();
    // Throttle a 80ms para nao re-renderizar a cada pacote chegado
    // quando o usuario tem muitos decks pequenos.
    if (renderQueued) return;
    const wait = Math.max(0, 80 - (now - lastRenderAt));
    renderQueued = true;
    setTimeout(() => {
      renderQueued = false;
      lastRenderAt = performance.now();
      try { renderBrowserRows(decks); } catch (error) { console.error(error); }
    }, wait);
  }

  const fetchPromises = streamCardsByDeck(decks, scheduleProgressiveRender);

  // Quando TODOS os decks terminam, faz uma renderizacao final garantida
  // (cobre o caso onde o ultimo deck disparou enquanto outro render
  // throttled estava pendente) e sincroniza o cache local.
  await Promise.allSettled(fetchPromises);
  dispatchBrowserDataReady(decks);
  try { renderBrowserRows(decks); } catch (error) { console.error(error); }
  console.timeEnd("browser-load-total");
});
