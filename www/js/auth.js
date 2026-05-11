async function login(email, password) {
  try {
    const apiUser = await apiLogin(email, password);
    saveToStorage("suinda_current_user", apiUser);
    return { user: apiUser, error: null };
  } catch (error) {
    if (error && error.status === 401) {
      return { user: null, error: "invalid_credentials" };
    }
    console.error("Falha ao contatar a API de login.", error);
    return { user: null, error: "api_unreachable" };
  }
}

function logout() {
  removeFromStorage("suinda_current_user");
  clearApiToken();
}

function getCurrentUser() {
  return getFromStorage("suinda_current_user");
}

function requireAuth() {
  const user = getCurrentUser();
  if (!user) {
    window.location.href = "login.html";
  }
}
