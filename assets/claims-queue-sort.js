(() => {
  const table = document.querySelector("table.claims-queue");
  const body = table?.tBodies[0];
  const headers = table?.tHead?.rows[0]?.cells;
  if (!table || !body || !headers) return;

  const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });
  let sortedColumn = -1;
  let direction = 1;

  const sortBy = (column) => {
    direction = sortedColumn === column ? -direction : 1;
    sortedColumn = column;
    const rows = Array.from(body.rows).filter((row) => row.cells.length === headers.length);

    rows.sort((left, right) => {
      const a = left.cells[column].innerText.replace(/\s+/g, " ").trim();
      const b = right.cells[column].innerText.replace(/\s+/g, " ").trim();
      let comparison;

      if (column === 6) {
        const aDate = Date.parse(a);
        const bDate = Date.parse(b);
        const aValid = Number.isFinite(aDate);
        const bValid = Number.isFinite(bDate);
        if (aValid !== bValid) return aValid ? -1 : 1;
        comparison = aValid ? aDate - bDate : collator.compare(a, b);
      } else {
        comparison = collator.compare(a, b);
      }

      return comparison * direction;
    });

    rows.forEach((row) => body.appendChild(row));
    Array.from(headers).forEach((header, index) => {
      header.setAttribute("aria-sort", index === column ? (direction === 1 ? "ascending" : "descending") : "none");
    });
  };

  Array.from(headers).forEach((header, column) => {
    header.setAttribute("role", "button");
    header.setAttribute("tabindex", "0");
    header.setAttribute("aria-sort", "none");
    header.setAttribute("aria-label", `Sort by ${header.innerText.trim()}`);
    header.addEventListener("click", () => sortBy(column));
    header.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        sortBy(column);
      }
    });
  });
})();
