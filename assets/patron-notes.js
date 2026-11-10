document.addEventListener("submit", async (event) => {
  const form = event.target.closest("form.patron-note-delete");
  if (!form) return;
  event.preventDefault();
  if (!window.confirm("Delete this note from the patron's Alma record?")) return;

  const button = form.querySelector("button[type=submit]");
  if (button) {
    button.disabled = true;
    button.textContent = "Deleting…";
  }
  try {
    const response = await fetch(form.action, {
      method: "POST",
      body: new FormData(form),
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || "Could not delete this note.");

    const note = form.closest("[data-patron-note]");
    const list = note?.parentElement;
    note?.remove();
    if (list && !list.querySelector("[data-patron-note]")) {
      const empty = document.createElement("p");
      empty.className = "muted";
      empty.textContent = "No patron notes were returned.";
      list.replaceWith(empty);
    }
  } catch (error) {
    window.alert(error.message || "Could not delete this note.");
    if (button) {
      button.disabled = false;
      button.textContent = "Delete note";
    }
  }
});
