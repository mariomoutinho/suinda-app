// Helpers compartilhados entre a tela de estudo (study.js) e o navegador de
// cartoes (card-browser-page.js). A toolbar e o ciclo de vida do editor ricos
// vivem em suinda-shell.js (showSuindaCardEditor / buildSuindaRichToolbar /
// setupSuindaRichToolbar); este arquivo expoe apenas as funcoes de sanitizacao
// e conversoes auxiliares que ambas as paginas precisam para ler/salvar HTML.

function sanitizeCardHtml(html) {
  const template = document.createElement("template");
  template.innerHTML = String(html || "").replace(/\[sound:[^\]]+\]/gi, "");

  const allowedTags = new Set([
    "B", "STRONG", "I", "EM", "U", "BR", "HR", "DIV", "P", "SPAN",
    "UL", "OL", "LI", "SUP", "SUB", "IMG", "FIGURE", "FIGCAPTION",
    "TABLE", "THEAD", "TBODY", "TFOOT", "TR", "TD", "TH",
    "H1", "H2", "H3", "H4", "H5", "H6",
    "SVG", "G", "RECT", "CIRCLE", "ELLIPSE", "LINE", "POLYLINE",
    "POLYGON", "PATH", "TEXT", "TSPAN", "DEFS", "USE", "IMAGE",
    "AUDIO", "VIDEO", "SOURCE", "FONT"
  ]);
  const allowedAttrs = new Set([
    "src", "alt", "title", "width", "height", "viewBox", "xmlns",
    "x", "y", "cx", "cy", "r", "rx", "ry", "x1", "x2", "y1", "y2",
    "d", "points", "fill", "stroke", "stroke-width", "transform",
    "href", "xlink:href", "preserveAspectRatio",
    "controls", "autoplay", "loop", "muted", "preload", "type",
    "color", "face", "size", "style"
  ]);

  const allowedStyleProps = new Set([
    "color", "background-color", "text-align", "font-weight", "font-style",
    "text-decoration", "max-width"
  ]);

  function sanitizeStyle(styleValue) {
    return String(styleValue || "")
      .split(";")
      .map(decl => decl.trim())
      .filter(decl => decl.length > 0)
      .map(decl => {
        const colonIndex = decl.indexOf(":");
        if (colonIndex < 0) return null;
        const prop = decl.slice(0, colonIndex).trim().toLowerCase();
        const val = decl.slice(colonIndex + 1).trim();
        if (!allowedStyleProps.has(prop)) return null;
        if (/expression\(|url\(|javascript:/i.test(val)) return null;
        return `${prop}: ${val}`;
      })
      .filter(Boolean)
      .join("; ");
  }

  template.content.querySelectorAll("*").forEach(node => {
    if (!allowedTags.has(node.tagName)) {
      node.replaceWith(document.createTextNode(node.textContent || ""));
      return;
    }

    [...node.attributes].forEach(attr => {
      const name = attr.name;
      const value = attr.value.trim();
      if (name.toLowerCase().startsWith("on") || !allowedAttrs.has(name)) {
        node.removeAttribute(name);
        return;
      }

      if (name === "style") {
        const clean = sanitizeStyle(value);
        if (clean) node.setAttribute("style", clean);
        else node.removeAttribute("style");
        return;
      }

      if (/^(src|href|xlink:href)$/i.test(name) && /^(javascript|vbscript|data:text\/html)/i.test(value)) {
        node.removeAttribute(name);
      }
    });
  });

  return template.innerHTML;
}

function htmlToPlainText(html) {
  const tmp = document.createElement("div");
  tmp.innerHTML = String(html || "");
  return (tmp.textContent || "").replace(/\s+/g, " ").trim();
}

function escapeHtmlForRichEditor(text) {
  return String(text)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll("\n", "<br>");
}
