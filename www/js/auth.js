async function login(email, password) {
  try {
    const apiUser = await apiLogin(email, password);
    saveToStorage("suinda_current_user", apiUser);
    return apiUser;
  } catch (error) {
    console.warn("API indisponivel, usando login local.", error);
  }

  const user = mockUsers.find(
    (item) => item.email === email && item.password === password && item.active
  );

  if (!user) return null;

  const safeUser = {
    id: user.id,
    name: user.name,
    email: user.email,
    role: user.role
  };

  saveToStorage("suinda_current_user", safeUser);
  return safeUser;
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
