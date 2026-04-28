document.addEventListener("DOMContentLoaded", () => {
  const path = window.location.pathname;

  if (
    path.includes("home.html") ||
    path.includes("decks.html") ||
    path.includes("deck-detail.html") ||
    path.includes("progress.html") ||
    path.includes("profile.html") ||
    path.includes("add.html")
  ) {
    requireAuth();
  }

  const loginForm = document.getElementById("loginForm");
  if (loginForm) {
    loginForm.addEventListener("submit", async (event) => {
      event.preventDefault();

      const email = document.getElementById("email").value.trim();
      const password = document.getElementById("password").value.trim();
      const message = document.getElementById("loginMessage");

      const user = await login(email, password);

      if (user) {
        message.style.color = "green";
        message.textContent = "Login realizado com sucesso.";
        window.location.href = "decks.html";
      } else {
        message.style.color = "var(--danger)";
        message.textContent = "E-mail ou senha inválidos.";
      }
    });
  }

  const user = getCurrentUser();

  const welcomeText = document.getElementById("welcomeText");
  const welcomeTitle = document.getElementById("welcomeTitle");

  if (user && welcomeTitle) {
    welcomeTitle.textContent = `Olá, ${user.name}`;
  }

  if (user && welcomeText) {
    welcomeText.textContent = "Seu espaço de estudo por cards.";
  }

  const profileName = document.getElementById("profileName");
  const profileEmail = document.getElementById("profileEmail");
  if (profileName && profileEmail && user) {
    profileName.textContent = user.name;
    profileEmail.textContent = user.email;
  }

  const logoutBtn = document.getElementById("logoutBtn");
  if (logoutBtn) {
    logoutBtn.addEventListener("click", () => {
      logout();
      window.location.href = "login.html";
    });
  }

  const profileLogoutBtn = document.getElementById("profileLogoutBtn");
  if (profileLogoutBtn) {
    profileLogoutBtn.addEventListener("click", () => {
      logout();
      window.location.href = "login.html";
    });
  }

  const homeSummary = document.getElementById("homeSummary");
  if (homeSummary) {
    loadStudyHistoryFromApi().then(() => {
    const summary = getProgressSummary();

    homeSummary.innerHTML = `
      <div class="progress-box">
        <strong>${summary.totalSessions}</strong>
        <span>Sessões</span>
      </div>
      <div class="progress-box">
        <strong>${summary.totalCards}</strong>
        <span>Cards respondidos</span>
      </div>
      <div class="progress-box">
        <strong>${summary.easy + summary.veryEasy}</strong>
        <span>Boas respostas</span>
      </div>
      <div class="progress-box">
        <strong>${summary.wrong}</strong>
        <span>Erros</span>
      </div>
    `;
    });
  }

  const lastSessionCard = document.getElementById("lastSessionCard");
  if (lastSessionCard) {
    loadStudyHistoryFromApi().then(() => {
    const history = getStudyHistory();

    if (history.length > 0) {
      const lastSession = history[history.length - 1];

      lastSessionCard.innerHTML = `
        <div class="last-session-box">
          <h3>${lastSession.deckTitle}</h3>
          <p><strong>Total:</strong> ${lastSession.total} cards</p>
          <p><strong>Errei:</strong> ${lastSession.wrong}</p>
          <p><strong>Difícil:</strong> ${lastSession.hard}</p>
          <p><strong>Fácil:</strong> ${lastSession.easy}</p>
          <p><strong>Muito fácil:</strong> ${lastSession.veryEasy}</p>
        </div>
      `;
    }
    });
  }

  renderDecks();
  renderDeckDetail();
});
