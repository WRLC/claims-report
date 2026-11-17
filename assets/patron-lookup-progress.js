(() => {
  const rows = document.getElementById("patron-loan-rows");
  if (!rows || rows.dataset.progressive !== "1") return;
  const table = rows.closest("table");
  let initialHomeRows = Array.from(rows.children).map(row => row.cloneNode(true));
  const status = document.createElement("p");
  status.className = "muted";
  status.setAttribute("role", "status");
  status.setAttribute("aria-live", "polite");
  table.insertAdjacentElement("afterend", status);

  const post = (mode, fields = {}) => {
    const body = new URLSearchParams(Object.assign({
      _csrf: rows.dataset.csrf,
      patron_primary_id: rows.dataset.patronId,
      mode
    }, fields));
    return fetch("patron-lookup-more.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
      body
    }).then(async response => {
      const raw = await response.text();
      let data;
      try { data = JSON.parse(raw); }
      catch { throw new Error(raw.trim() || `Additional lookup returned an empty response (HTTP ${response.status}).`); }
      if (!response.ok && !data.error) throw new Error(`Additional lookup failed (HTTP ${response.status}).`);
      if (!data.ok) throw new Error(data.error || "Additional Alma lookups failed.");
      return data;
    });
  };

  const renderRows = (loans, preserveHome = false) => {
    rows.replaceChildren();
    if (preserveHome) for (const row of initialHomeRows) rows.append(row.cloneNode(true));
    const oldEmpty = table.parentElement.querySelector(".patron-progress-empty");
    if (oldEmpty) oldEmpty.remove();
    for (const loan of loans) {
      const tr = document.createElement("tr");
      const cell = value => {
        const td = document.createElement("td");
        if (value instanceof Node) td.append(value);
        else td.textContent = value == null ? "" : String(value);
        tr.append(td);
        return td;
      };
      if (loan._open_case) {
        const link = document.createElement("a");
        link.href = `case.php?id=${encodeURIComponent(loan._open_case.id)}`;
        link.textContent = `View ${loan._open_case.public_id}`;
        cell(link);
      } else cell("—");
      const form = document.createElement("form");
      form.method = "post";
      const fields = {
        _csrf: rows.dataset.csrf,
        action: "select_item",
        patron_primary_id: rows.dataset.patronId,
        institution_id: rows.dataset.institutionId,
        loan_primary_id: loan._alma_primary_id || rows.dataset.patronId,
        loan_member: loan._alma_member || "",
        loan_case_category: loan._claims_case_type || "unconfirmed",
        loan_classification_status: loan._claims_classification_status || "unresolved",
        loan_partner_name: loan._claims_partner_name || "",
        loan_partner_email: loan._claims_partner_email || ""
      };
      for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = value;
        form.append(input);
      }
      const button = document.createElement("button");
      button.type = "submit";
      button.name = "item_barcode";
      button.value = loan.item_barcode || "";
      button.className = "secondary";
      button.textContent = loan.title || "Untitled item";
      form.append(button);
      cell(form);
      cell(loan.item_barcode);
      cell(loan._institution_name);
      const badge = document.createElement("span");
      badge.className = `case-type-pill case-type-${loan._case_type_key || "unconfirmed"}`;
      badge.textContent = loan._case_type_label || "Unconfirmed";
      cell(badge);
      cell(loan.call_number);
      cell(loan._due_label || loan.due_date);
      const library = loan.library;
      cell(typeof library === "object" ? (library && (library.desc || library.value)) || "" : library);
      rows.append(tr);
    }
  };

  const enableProvisionalHome = () => {
    for (const button of rows.querySelectorAll("button[disabled]")) button.disabled = false;
    for (const badge of rows.querySelectorAll(".case-type-pill")) {
      if (badge.textContent === "Checking…") badge.textContent = "Unconfirmed";
    }
  };

  const renderNotes = (notes, homeMember, canDelete) => {
    const previous = document.getElementById("progressive-patron-notes");
    if (previous) previous.remove();
    if (!notes || !notes.length) return;
    const section = document.createElement("section");
    section.id = "progressive-patron-notes";
    section.className = "card";
    const heading = document.createElement("h2");
    heading.textContent = "Patron notes";
    section.append(heading);
    const list = document.createElement("ul");
    list.className = "patron-note-list";
    for (const note of notes) {
      const li = document.createElement("li");
      if (note.type) {
        const type = document.createElement("strong");
        type.textContent = note.type;
        li.append(type, document.createElement("br"));
      }
      const text = document.createElement("p");
      text.style.whiteSpace = "pre-wrap";
      text.textContent = note.text;
      li.append(text);
      if (note.created_date) {
        const date = document.createElement("small");
        date.textContent = `Added ${note.created_date}`;
        li.append(date);
      }
      for (const delivery of note.email_status || []) {
        const detail = document.createElement("small");
        detail.className = "note-delivery";
        detail.textContent = delivery;
        li.append(detail);
      }
      if (canDelete) {
        const form = document.createElement("form");
        form.className = "patron-note-delete";
        form.method = "post";
        form.action = "patron-note.php";
        const fields = {
          _csrf: rows.dataset.csrf,
          patron_primary_id: rows.dataset.patronId,
          alma_member: homeMember,
          note_key: note.delete_key
        };
        for (const [name, value] of Object.entries(fields)) {
          const input = document.createElement("input");
          input.type = "hidden";
          input.name = name;
          input.value = value || "";
          form.append(input);
        }
        const button = document.createElement("button");
        button.type = "submit";
        button.className = "danger secondary";
        button.textContent = "Delete note";
        form.append(button);
        li.append(form);
      }
      list.append(li);
    }
    section.append(list);
    const parent = table.closest("section");
    if (parent) parent.insertAdjacentElement("afterend", section);
  };

  const loadAccounts = (profile, accounts) => {
    rows.dataset.institutionId = profile.institution_id || rows.dataset.institutionId;
    const results = new Array(accounts.length);
    let completed = 0;
    let homeReady = true;
    const failed = [];
    status.textContent = `Home institution items are shown. Loading holdings and case types from ${accounts.length} institutions in the background…`;
    accounts.forEach((account, index) => {
      const fields = Object.assign({}, profile, { member: account.institution, primary_id: account.primary_id });
      post("member", fields).then(data => {
        results[index] = data.loans;
        if (index === 0) homeReady = true;
      }).catch(error => {
        failed.push(`${account.institution}: ${error.message}`);
        if (index === 0) homeReady = true;
      }).finally(() => {
        completed++;
        if (homeReady) renderRows(results.reduce((all, value) => all.concat(value || []), []), !results[0]);
        if (completed === accounts.length) {
          if (!results[0]) enableProvisionalHome();
          status.textContent = failed.length
            ? `Loaded ${completed - failed.length} of ${accounts.length} institution accounts. ${failed.join(" ")}`
            : `All ${accounts.length} institution accounts and case types are loaded.`;
          if (homeReady && results.every(value => !value || !value.length)) {
            const empty = document.createElement("p");
            empty.className = "empty patron-progress-empty";
            empty.textContent = "No active checked-out items were found for this patron.";
            table.insertAdjacentElement("afterend", empty);
          }
        } else {
          status.textContent = `Background lookup: ${completed} of ${accounts.length} institution accounts returned${failed.length ? `; ${failed.length} failed` : ""}…`;
        }
      });
    });
  };

  const loadAccountsFor = profile => post("accounts", profile).then(data => {
    const accountProfile = {
      home_member: data.home_member,
      home_primary_id: data.home_primary_id,
      source_link_id: data.source_link_id,
      source_institution_code: data.source_institution_code,
      institution_id: data.institution_id
    };
    loadAccounts(accountProfile, data.accounts || []);
  });

  if (rows.dataset.stage === "home") {
    status.textContent = "Finding the patron’s home institution and loading its items…";
    post("home").then(data => {
      rows.dataset.institutionId = data.institution_id || rows.dataset.institutionId;
      const section = table.closest("section");
      const heading = section ? section.querySelector("h2") : null;
      if (heading && data.name) heading.textContent = `Checked-out items for ${data.name}${data.institution_name ? ` · ${data.institution_name}` : ""}`;
      renderRows(data.loans || []);
      initialHomeRows = Array.from(rows.children).map(row => row.cloneNode(true));
      renderNotes(data.notes, data.home_member, data.can_delete_patron_notes);
      return loadAccountsFor({
        home_member: data.home_member,
        home_primary_id: data.home_primary_id,
        source_link_id: data.source_link_id,
        source_institution_code: data.source_institution_code,
        institution_id: data.institution_id
      });
    }).catch(error => { enableProvisionalHome(); status.textContent = error.message; });
  } else {
    loadAccountsFor({}).catch(error => { enableProvisionalHome(); status.textContent = error.message; });
  }
})();
