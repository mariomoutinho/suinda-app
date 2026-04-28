function getLocalDailyStats() {
  const totals = {};

  getStudyHistory().forEach(session => {
    if (!session.createdAt) return;
    const day = session.createdAt.slice(0, 10);
    if (!totals[day]) {
      totals[day] = { day, totalCards: 0, totalSeconds: 0 };
    }
    totals[day].totalCards += session.total || 0;
    totals[day].totalSeconds += session.durationInSeconds || 0;
  });

  return Object.values(totals).sort((a, b) => a.day.localeCompare(b.day)).slice(-30);
}

async function loadDailyStats() {
  try {
    return await apiGetDailyStats();
  } catch (error) {
    await loadStudyHistoryFromApi();
    return getLocalDailyStats();
  }
}

function drawDailyStatsChart(days) {
  const canvas = document.getElementById("dailyStatsChart");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");
  const width = canvas.width;
  const height = canvas.height;
  const padding = 34;
  const chartWidth = width - padding * 2;
  const chartHeight = height - padding * 2;
  const safeDays = days.length ? days : [{ day: "Hoje", totalCards: 0, totalSeconds: 0 }];
  const maxCards = Math.max(1, ...safeDays.map(day => day.totalCards));
  const maxMinutes = Math.max(1, ...safeDays.map(day => Math.round(day.totalSeconds / 60)));
  const maxValue = Math.max(maxCards, maxMinutes);
  const barGap = 6;
  const barWidth = Math.max(8, (chartWidth / safeDays.length) - barGap);

  ctx.clearRect(0, 0, width, height);
  ctx.fillStyle = "#f7f7f7";
  ctx.fillRect(0, 0, width, height);

  ctx.strokeStyle = "#e5e2dc";
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(padding, padding);
  ctx.lineTo(padding, height - padding);
  ctx.lineTo(width - padding, height - padding);
  ctx.stroke();

  safeDays.forEach((day, index) => {
    const x = padding + index * (barWidth + barGap);
    const cardsHeight = (day.totalCards / maxValue) * chartHeight;
    const minutes = Math.round(day.totalSeconds / 60);
    const minutesHeight = (minutes / maxValue) * chartHeight;

    ctx.fillStyle = "#1f4d5c";
    ctx.fillRect(x, height - padding - cardsHeight, barWidth * 0.48, cardsHeight);
    ctx.fillStyle = "#d9a441";
    ctx.fillRect(x + barWidth * 0.52, height - padding - minutesHeight, barWidth * 0.48, minutesHeight);
  });

  ctx.fillStyle = "#666";
  ctx.font = "13px Arial";
  ctx.textAlign = "center";
  ctx.fillText("Cartões e minutos estudados por dia", width / 2, 20);
}

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();
  const days = await loadDailyStats();
  drawDailyStatsChart(days);

  const decks = await loadDecksFromApi();
  const totalCards = decks.reduce((total, deck) => total + (deck.totalCards || 0), 0);
  const user = getCurrentUser();
  let newCards = totalCards;
  let reviewCards = 0;

  if (user && typeof getStudyCountsForDeck === "function") {
    await Promise.all(decks.map(deck => loadCardsFromApi(deck.id, { includeMedia: false })));
    const totals = decks.reduce((acc, deck) => {
      const counts = getStudyCountsForDeck(user.id, deck.id, new Date());
      acc.new += counts.new;
      acc.review += counts.review;
      return acc;
    }, { new: 0, review: 0 });
    newCards = totals.new;
    reviewCards = totals.review;
  }

  const statTotalCards = document.getElementById("statTotalCards");
  const statNewCards = document.getElementById("statNewCards");
  const statReviewCards = document.getElementById("statReviewCards");
  if (statTotalCards) statTotalCards.textContent = totalCards;
  if (statNewCards) statNewCards.textContent = newCards;
  if (statReviewCards) statReviewCards.textContent = reviewCards;
});
