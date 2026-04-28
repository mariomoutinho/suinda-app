async function renderDecks() {
  const deckList = document.getElementById("deckList");
  if (!deckList) return;

  deckList.innerHTML = "<p class=\"muted-line\">Carregando baralhos...</p>";

  const decks = await loadDecksFromApi();

  const sourceWarning = localStorage.getItem("suinda_last_data_source") === "local"
    ? `<p class="message error-line">Mostrando dados locais. Verifique se o backend MySQL esta rodando.</p>`
    : "";

  const user = getCurrentUser();

  await Promise.all(decks.map(deck => loadCardsFromApi(deck.id, { includeMedia: false })));

  const deckRows = decks.map(deck => {
    const counts = user && typeof getStudyCountsForDeck === "function"
      ? getStudyCountsForDeck(user.id, deck.id, new Date())
      : { new: deck.totalCards || 0, learning: 0, review: 0 };

    const directCounts = user && typeof getDirectStudyCountsForDeck === "function"
      ? getDirectStudyCountsForDeck(user.id, deck.id, new Date())
      : counts;

    return {
      ...deck,
      counts,
      directCounts
    };
  });

  const totalPending = deckRows.reduce((total, deck) => (
    total + deck.counts.new + deck.counts.learning + deck.counts.review
  ), 0);

  const pendingLine = document.getElementById("pendingCardsLine");
  if (pendingLine) {
    pendingLine.textContent = `${totalPending} cartoes pendentes`;
  }

  if (!deckRows.length) {
    deckList.innerHTML = sourceWarning + `<p class="muted-line">Nenhum baralho encontrado.</p>`;
    return;
  }

  const tree = buildDeckTree(deckRows);

  deckList.innerHTML = sourceWarning + tree
    .map(node => renderDeckGroupSection(node.parent, node.children))
    .join("");

  setupDeckGroupActions();
}

function buildDeckTree(decks) {
  const topLevelDecks = decks.filter(deck => !String(deck.title || "").includes("::"));
  const subDecks = decks.filter(deck => String(deck.title || "").includes("::"));

  const tree = topLevelDecks.map(parent => {
    const parentTitle = String(parent.title || "").trim();

    const children = subDecks.filter(child => {
      const childParentTitle = getParentTitleFromSubDeck(child.title);
      return normalizeDeckTitle(childParentTitle) === normalizeDeckTitle(parentTitle);
    });

    return {
      parent,
      children
    };
  });

  const orphanSubDeckGroups = {};

  subDecks.forEach(subDeck => {
    const parentTitle = getParentTitleFromSubDeck(subDeck.title);
    const hasRealParent = topLevelDecks.some(parent => {
      return normalizeDeckTitle(parent.title) === normalizeDeckTitle(parentTitle);
    });

    if (!hasRealParent) {
      if (!orphanSubDeckGroups[parentTitle]) {
        orphanSubDeckGroups[parentTitle] = [];
      }

      orphanSubDeckGroups[parentTitle].push(subDeck);
    }
  });

  Object.entries(orphanSubDeckGroups).forEach(([parentTitle, children]) => {
    const fakeParent = {
      id: children[0]?.id,
      title: parentTitle,
      description: "Baralho importado.",
      category: children[0]?.category || "Baralhos",
      totalCards: 0,
      counts: children.reduce((total, deck) => ({
        new: total.new + deck.counts.new,
        learning: total.learning + deck.counts.learning,
        review: total.review + deck.counts.review
      }), { new: 0, learning: 0, review: 0 }),
      directCounts: { new: 0, learning: 0, review: 0 },
      isVirtualParent: true
    };

    tree.push({
      parent: fakeParent,
      children
    });
  });

  return tree;
}

function renderDeckGroupSection(parentDeck, childDecks = []) {
  const hasChildren = childDecks.length > 0;

  const aggregateCounts = getAggregateDeckCounts(parentDeck, childDecks);

  const contextDeckAttribute = parentDeck && parentDeck.id
    ? `data-context-deck-id="${parentDeck.id}"`
    : "";

  const titleMarkup = parentDeck && parentDeck.id && !parentDeck.isVirtualParent
    ? `
      <a
        class="deck-group-title-link"
        href="study.html?id=${parentDeck.id}"
      >
        ${escapeHtml(parentDeck.title)}
        ${renderDeckPresetBadge(parentDeck.id)}
      </a>
    `
    : `
      <span class="deck-group-title-text">
        ${escapeHtml(parentDeck.title)}
      </span>
    `;

  const expanderMarkup = hasChildren
    ? `
      <button
        type="button"
        class="deck-group-expander"
        data-group-toggle
        aria-label="Expandir sub-baralhos"
        aria-expanded="false"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M9 6l6 6-6 6"></path>
        </svg>
      </button>
    `
    : `<span class="deck-group-expander-spacer" aria-hidden="true"></span>`;

  return `
    <section
      class="deck-group ${hasChildren ? "has-children" : "no-children"}"
      ${contextDeckAttribute}
    >
      <div class="deck-group-summary">
        ${expanderMarkup}
        ${titleMarkup}
        ${renderDeckCounters(aggregateCounts)}
      </div>

      <div class="deck-group-items" hidden>
        ${hasChildren ? childDecks.map(renderDeckTreeRow).join("") : ""}
      </div>
    </section>
  `;
}

function getAggregateDeckCounts(parentDeck, childDecks = []) {
  const parentCounts = parentDeck?.counts || parentDeck?.directCounts || {
    new: 0,
    learning: 0,
    review: 0
  };

  if (childDecks.length > 0) {
    return {
      new: parentCounts.new || 0,
      learning: parentCounts.learning || 0,
      review: parentCounts.review || 0
    };
  }

  return {
    new: parentCounts.new || 0,
    learning: parentCounts.learning || 0,
    review: parentCounts.review || 0
  };
}

function setupDeckGroupActions() {
  document.querySelectorAll(".deck-group").forEach(group => {
    const toggle = group.querySelector("[data-group-toggle]");
    const items = group.querySelector(".deck-group-items");
    const titleLink = group.querySelector(".deck-group-title-link");

    if (titleLink) {
      titleLink.addEventListener("click", event => {
        event.stopPropagation();
      });
    }

    if (!toggle || !items) return;

    toggle.addEventListener("click", event => {
      event.preventDefault();
      event.stopPropagation();

      const isOpen = group.classList.toggle("is-open");
      items.hidden = !isOpen;
      toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });
  });
}

function getParentTitleFromSubDeck(title) {
  return String(title || "").split("::")[0].trim();
}

function getChildTitleFromSubDeck(title) {
  const parts = String(title || "").split("::");
  return parts[parts.length - 1].trim();
}

function normalizeDeckTitle(title) {
  return String(title || "").trim().toLowerCase();
}

function getSubDecksOf(parentDeck) {
  const parentTitle = String(parentDeck.title || "").trim();
  if (!parentTitle) return [];

  return mockDecks.filter(deck => {
    const title = String(deck.title || "").trim();
    return title.startsWith(`${parentTitle}::`);
  });
}

function buildRenamedSubDeckTitle(oldParentTitle, newParentTitle, subDeckTitle) {
  const oldPrefix = `${oldParentTitle}::`;

  if (!String(subDeckTitle || "").startsWith(oldPrefix)) {
    return subDeckTitle;
  }

  const childPart = String(subDeckTitle).slice(oldPrefix.length);
  return `${newParentTitle}::${childPart}`;
}

async function setupDeckQuickActions() {
  const fabButton = document.getElementById("deckFabButton");
  const fabMenu = document.getElementById("deckFabMenu");
  const deckDialog = document.getElementById("quickDeckDialog");
  const deckForm = document.getElementById("quickDeckForm");
  const deckTitle = document.getElementById("quickDeckTitle");
  const cardDialog = document.getElementById("quickCardDialog");
  const cardForm = document.getElementById("quickCardForm");
  const cardDeck = document.getElementById("quickCardDeck");
  const importFile = document.getElementById("quickImportFile");
  const deckContextDialog = document.getElementById("deckContextDialog");
  const deckContextTitle = document.getElementById("deckContextTitle");

  let contextDeck = null;
  let suppressNextDeckClick = false;
  let longPressTimer = null;

  if (!fabButton || !fabMenu) return;

  function closeFabMenu() {
    fabMenu.classList.remove("open");
    fabMenu.setAttribute("aria-hidden", "true");
  }

  function openDeckDialog() {
    closeFabMenu();
    deckDialog?.showModal();
    setTimeout(() => deckTitle?.focus(), 50);
  }

  async function openCardDialog(selectedDeckId = null) {
    closeFabMenu();

    const decks = await loadDecksFromApi();

    if (cardDeck) {
      cardDeck.innerHTML = decks
        .map(deck => `<option value="${deck.id}">${escapeHtml(deck.title)}</option>`)
        .join("");

      if (selectedDeckId) {
        cardDeck.value = String(selectedDeckId);
      }
    }

    cardDialog?.showModal();
    setTimeout(() => document.getElementById("quickCardFront")?.focus(), 50);
  }

  function getDeckById(deckId) {
    return mockDecks.find(deck => Number(deck.id) === Number(deckId)) || null;
  }

  function saveLocalDecks() {
    saveToStorage("suinda_local_decks", mockDecks);
  }

  async function persistDeckUpdate(deck, changes) {
    const nextDeck = { ...deck, ...changes };

    try {
      const updated = await apiUpdateDeck(deck.id, nextDeck);
      const index = mockDecks.findIndex(item => Number(item.id) === Number(deck.id));

      if (index >= 0) {
        mockDecks[index] = updated;
      }

      return updated;
    } catch (error) {
      const index = mockDecks.findIndex(item => Number(item.id) === Number(deck.id));

      if (index >= 0) {
        mockDecks[index] = nextDeck;
      }

      saveLocalDecks();
      return nextDeck;
    }
  }

  async function renameDeckAndKeepSubDecks(deck, newTitle) {
    const oldTitle = String(deck.title || "").trim();
    const cleanNewTitle = String(newTitle || "").trim();

    if (!oldTitle || !cleanNewTitle || oldTitle === cleanNewTitle) return;

    const subDecks = getSubDecksOf(deck);

    await persistDeckUpdate(deck, { title: cleanNewTitle });

    for (const subDeck of subDecks) {
      const nextSubDeckTitle = buildRenamedSubDeckTitle(
        oldTitle,
        cleanNewTitle,
        subDeck.title
      );

      await persistDeckUpdate(subDeck, { title: nextSubDeckTitle });
    }

    saveLocalDecks();
  }

  async function persistDeckDelete(deck) {
    const deckIdsToDelete = new Set([
      Number(deck.id),
      ...getSubDecksOf(deck).map(subDeck => Number(subDeck.id))
    ]);

    try {
      await apiDeleteDeck(deck.id);
    } catch (error) {
      // Mantem a UI utilizavel offline; depois pode sincronizar.
    }

    replaceArrayContent(
      mockDecks,
      mockDecks.filter(item => !deckIdsToDelete.has(Number(item.id)))
    );

    replaceArrayContent(
      mockCards,
      mockCards.filter(card => !deckIdsToDelete.has(Number(card.deckId)))
    );

    saveLocalDecks();
    saveToStorage("suinda_local_cards", mockCards);
  }

  function openDeckContextMenu(deckId) {
    const deck = getDeckById(deckId);
    if (!deck || !deckContextDialog) return;

    contextDeck = deck;

    if (deckContextTitle) {
      deckContextTitle.textContent = deck.title;
    }

    closeFabMenu();
    deckContextDialog.showModal();
  }

  function closeDeckContextMenu() {
    deckContextDialog?.close();
  }

  async function createSubDeck(parentDeck) {
    const name = await showSuindaPrompt({
      title: "Criar sub-baralho",
      label: "Nome do sub-baralho"
    });
    if (!name || !name.trim()) return;

    const parentTitle = String(parentDeck.title || "").trim();
    const childName = name.trim();
    const title = `${parentTitle}::${childName}`;

    const data = {
      title,
      category: parentDeck.category || "Geral",
      description: `Sub-baralho de ${parentTitle}.`
    };

    try {
      await apiCreateDeck(data);
    } catch (error) {
      createLocalDeck(data);
    }

    await renderDecks();
  }

  async function exportDeck(deck) {
    const cards = await loadCardsFromApi(deck.id);

    const payload = {
      exportedAt: new Date().toISOString(),
      deck,
      cards
    };

    const blob = new Blob(
      [JSON.stringify(payload, null, 2)],
      { type: "application/json" }
    );

    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");

    link.href = url;
    link.download = `${deck.title.replace(/[\\/:*?"<>|]+/g, "-")}.json`;

    document.body.appendChild(link);
    link.click();
    link.remove();

    URL.revokeObjectURL(url);
  }

  async function createDeckShortcut(deck) {
    const url = `${window.location.origin}${window.location.pathname.replace(/decks\.html$/, "deck-detail.html")}?id=${deck.id}`;

    if (navigator.share) {
      await navigator.share({ title: deck.title, url });
      return;
    }

    if (navigator.clipboard) {
      await navigator.clipboard.writeText(url);
      showSuindaToast("Link do baralho copiado.");
      return;
    }

    await showSuindaPrompt({
      title: "Link do baralho",
      label: "Copie o link",
      value: url
    });
  }

  async function runDeckContextAction(action) {
    if (!contextDeck) return;

    const deck = contextDeck;
    closeDeckContextMenu();

    if (action === "add") {
      await openCardDialog(deck.id);
      return;
    }

    if (action === "browse") {
      window.location.href = `card-browser.html?deckId=${deck.id}`;
      return;
    }

    if (action === "rename") {
      const title = await showSuindaPrompt({
        title: "Renomear baralho",
        label: "Novo nome",
        value: deck.title
      });
      if (!title || !title.trim()) return;

      await renameDeckAndKeepSubDecks(deck, title.trim());
      await renderDecks();
      return;
    }

    if (action === "subdeck") {
      await createSubDeck(deck);
      return;
    }

    if (action === "options") {
      window.location.href = `deck-options.html?id=${deck.id}`;
      return;
    }

    if (action === "custom-study") {
      window.location.href = `study.html?id=${deck.id}&custom=1`;
      return;
    }

    if (action === "export") {
      await exportDeck(deck);
      return;
    }

    if (action === "shortcut") {
      await createDeckShortcut(deck);
      return;
    }

    if (action === "description") {
      const description = await showSuindaPrompt({
        title: "Editar descricao",
        label: "Descricao do baralho",
        value: deck.description || "",
        multiline: true
      });
      if (description === null) return;

      await persistDeckUpdate(deck, {
        description: description.trim() || "Baralho criado pelo usuario."
      });

      await renderDecks();
      return;
    }

    if (action === "delete") {
      const confirmed = await showSuindaConfirm({
        title: "Excluir baralho",
        message: `Excluir o baralho "${deck.title}" e seus cartoes?`,
        confirmText: "Excluir",
        danger: true
      });
      if (!confirmed) return;

      await persistDeckDelete(deck);
      await renderDecks();
    }
  }

  fabButton.addEventListener("click", event => {
    event.stopPropagation();

    fabMenu.classList.toggle("open");
    fabMenu.setAttribute(
      "aria-hidden",
      fabMenu.classList.contains("open") ? "false" : "true"
    );
  });

  document.addEventListener("click", event => {
    if (
      !event.target.closest(".fab-speed-dial") &&
      !event.target.closest("#deckFabButton")
    ) {
      closeFabMenu();
    }
  });

  document.querySelectorAll("[data-open-deck-modal]").forEach(button => {
    button.addEventListener("click", openDeckDialog);
  });

  document.querySelectorAll("[data-open-card-modal]").forEach(button => {
    button.addEventListener("click", () => openCardDialog());
  });

  document.querySelectorAll("[data-open-import]").forEach(button => {
    button.addEventListener("click", () => {
      closeFabMenu();
      importFile?.click();
    });
  });

  document.querySelectorAll("[data-close-dialog]").forEach(button => {
    button.addEventListener("click", () => {
      button.closest("dialog")?.close();
    });
  });

  deckForm?.addEventListener("submit", async event => {
    event.preventDefault();

    const title = deckTitle.value.trim();
    if (!title) return;

    const data = {
      title,
      category: "Geral",
      description: "Baralho criado pelo usuario."
    };

    try {
      await apiCreateDeck(data);
    } catch (error) {
      createLocalDeck(data);
    }

    deckTitle.value = "";
    deckDialog.close();

    await renderDecks();
  });

  cardForm?.addEventListener("submit", async event => {
    event.preventDefault();

    const deckId = Number(cardDeck.value);
    const question = document.getElementById("quickCardFront").value.trim();
    const answer = document.getElementById("quickCardBack").value.trim();
    const cardType = document.getElementById("quickCardType").value;

    if (!deckId || !question || !answer) return;

    await apiCreateCard(deckId, {
      question,
      answer,
      questionHtml: null,
      answerHtml: null,
      cardType,
      imageData: null,
      audioData: null,
      occlusionMasks: []
    });

    cardForm.reset();
    cardDialog.close();

    await renderDecks();
  });

  importFile?.addEventListener("change", async () => {
    const file = importFile.files?.[0];
    if (!file) return;

    const decks = await loadDecksFromApi();
    const firstDeck = decks[0];
    const progress = typeof showSuindaProgress === "function"
      ? showSuindaProgress({
        title: "Importando baralho",
        message: "Preparando arquivo...",
        percent: 3
      })
      : null;

    if (!firstDeck && !file.name.toLowerCase().endsWith(".apkg")) {
      progress?.close();
      return;
    }

    try {
      if (file.name.toLowerCase().endsWith(".apkg")) {
        await apiImportApkg(firstDeck?.id || 0, file, {
          autoCreateDeck: true,
          deckTitle: file.name.replace(/\.apkg$/i, ""),
          onProgress: info => {
            const processing = info.phase === "done"
              ? "Finalizando importacao..."
              : info.phase === "upload" && info.percent >= 70
                ? "Arquivo enviado. Processando pacote Anki..."
                : "Enviando pacote Anki...";
            progress?.update({ message: processing, percent: info.percent });
          }
        });
      } else {
        progress?.update({ message: "Lendo arquivo de texto...", percent: 35 });
        const content = await file.text();
        progress?.update({ message: "Importando cartoes...", percent: 70 });
        await apiImportCards(firstDeck.id, content);
      }
    } catch (error) {
      showSuindaToast(error.message, "error");
    } finally {
      progress?.update({ message: "Atualizando baralhos...", percent: 100 });
      setTimeout(() => progress?.close(), 260);
      importFile.value = "";
      await renderDecks();
    }
  });

  deckContextDialog?.addEventListener("click", event => {
    if (event.target === deckContextDialog) {
      closeDeckContextMenu();
    }
  });

  deckContextDialog?.querySelectorAll("[data-deck-context-action]").forEach(button => {
    button.addEventListener("click", () => {
      runDeckContextAction(button.dataset.deckContextAction);
    });
  });

  document.addEventListener("pointerdown", event => {
    if (event.target.closest("[data-group-toggle]")) return;

    const contextTarget = event.target.closest("[data-context-deck-id]");
    if (!contextTarget || event.button > 0) return;

    const deckId = Number(contextTarget.dataset.contextDeckId);
    if (!deckId) return;

    clearTimeout(longPressTimer);

    longPressTimer = setTimeout(() => {
      suppressNextDeckClick = true;
      openDeckContextMenu(deckId);
    }, 560);
  });

  ["pointerup", "pointercancel", "pointerleave"].forEach(type => {
    document.addEventListener(type, () => {
      clearTimeout(longPressTimer);
    });
  });

  document.addEventListener("contextmenu", event => {
    if (event.target.closest("[data-group-toggle]")) return;

    const contextTarget = event.target.closest("[data-context-deck-id]");
    if (!contextTarget) return;

    const deckId = Number(contextTarget.dataset.contextDeckId);
    if (!deckId) return;

    event.preventDefault();
    openDeckContextMenu(deckId);
  });

  document.addEventListener("click", event => {
    const contextTarget = event.target.closest("[data-context-deck-id]");
    if (!contextTarget || !suppressNextDeckClick) return;

    event.preventDefault();
    event.stopPropagation();

    suppressNextDeckClick = false;
  }, true);
}

async function renderDeckDetail() {
  const container = document.getElementById("deckDetail");
  if (!container) return;

  const params = new URLSearchParams(window.location.search);
  const id = Number(params.get("id"));

  const deck = await loadDeckDetailFromApi(id);

  if (!deck) {
    container.innerHTML = "<p>Baralho nao encontrado.</p>";
    return;
  }

  container.innerHTML = `
    <div class="deck-detail-head">
      <div>
        <p class="eyebrow">Baralho</p>
        <h2>${escapeHtml(deck.title)}</h2>
        <p>${escapeHtml(deck.description)}</p>
      </div>
      <a class="icon-button" href="deck-options.html?id=${deck.id}" aria-label="Opcoes">⚙</a>
    </div>

    <div class="deck-meta compact">
      <span>${escapeHtml(deck.category)}</span>
      <strong>${deck.totalCards} cartoes</strong>
    </div>

    <a class="btn btn-primary full-width" href="study.html?id=${deck.id}">Iniciar estudo</a>
  `;
}

function getDeckGroup(deck) {
  const title = String(deck.title || "");

  if (title.includes("::")) {
    return title.split("::")[0].trim() || "Baralhos";
  }

  return title || deck.category || "Baralhos";
}

function renderDeckTreeRow(deck) {
  const rawTitle = String(deck.title || "");
  const title = rawTitle.includes("::")
    ? getChildTitleFromSubDeck(rawTitle)
    : rawTitle;

  return `
    <article
      class="deck-tree-row"
      data-deck-id="${deck.id}"
      data-context-deck-id="${deck.id}"
    >
      <a class="deck-tree-main" href="study.html?id=${deck.id}">
        <strong>${escapeHtml(title)}</strong>
        <span>${escapeHtml(deck.description || "Sem descricao")}</span>
        ${renderDeckPresetBadge(deck.id)}
      </a>

      ${renderDeckCounters(deck.counts)}
    </article>
  `;
}

function renderDeckPresetBadge(deckId) {
  if (typeof getDeckPresetName !== "function") return "";
  return `<small class="deck-preset-badge">${escapeHtml(getDeckPresetName(deckId))}</small>`;
}

function renderDeckCounters(counts) {
  return `
    <div class="deck-counters" aria-label="Contadores do baralho">
      <span class="count-new">${counts.new}</span>
      <span class="count-learning">${counts.learning}</span>
      <span class="count-review">${counts.review}</span>
    </div>
  `;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

document.addEventListener("DOMContentLoaded", setupDeckQuickActions);
