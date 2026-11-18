// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

const STORAGE_KEY = "assignment-portal.submissions.v1";
const FIELDS = ["name", "regNo", "title", "url"];
const URL_MESSAGE = "Enter a valid URL starting with http:// or https://";
const SVG_NS = "http://www.w3.org/2000/svg";

const state = {
  mode: "loading",
  rows: [],
  query: "",
  sending: false,
};

const form = document.getElementById("submission-form");
const formStatus = document.getElementById("form-status");
const formError = document.getElementById("form-error");
const submitButton = document.getElementById("submit-button");
const submitLabel = document.getElementById("submit-label");
const spinner = submitButton.querySelector(".spinner");
const searchInput = document.getElementById("search");
const listBody = document.getElementById("submission-list");
const listPanel = document.getElementById("list-panel");
const tableWrap = document.getElementById("table-wrap");
const countChip = document.getElementById("count-chip");
const statusLabel = document.getElementById("api-status-label");
const statusPill = document.getElementById("api-status");
const toast = document.getElementById("toast");

const inputs = Object.fromEntries(FIELDS.map((field) => [field, document.getElementById(field)]));

form.addEventListener("submit", onSubmit);
searchInput.addEventListener("input", () => {
  state.query = searchInput.value;
  if (state.mode === "live" || state.mode === "demo") {
    renderList();
  }
});
searchInput.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && searchInput.value !== "") {
    searchInput.value = "";
    state.query = "";
    renderList();
  }
});
listPanel.addEventListener("click", (event) => {
  const target = event.target;
  if (!(target instanceof Element)) {
    return;
  }
  if (target.closest("[data-action='retry']")) {
    load();
  }
});

for (const field of FIELDS) {
  inputs[field].addEventListener("input", () => clearFieldError(field));
}

load();
window.setInterval(refreshTimes, 30000);

async function load() {
  state.mode = "loading";
  renderList();
  try {
    const response = await fetch("api.php", {
      method: "GET",
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    const type = response.headers.get("Content-Type") || "";
    if (!response.ok || !type.toLowerCase().includes("application/json")) {
      enterDemo();
      return;
    }
    const payload = await response.json();
    if (!payload || payload.ok !== true || !Array.isArray(payload.submissions)) {
      showLoadError();
      return;
    }
    state.mode = "live";
    state.rows = payload.submissions;
    setStatus("live");
    renderList();
  } catch {
    showLoadError();
  }
}

function enterDemo() {
  state.mode = "demo";
  state.rows = readDemoRows();
  setStatus("demo");
  renderList();
}

function showLoadError() {
  state.mode = "error";
  state.rows = [];
  setStatus("down");
  renderList();
}

async function onSubmit(event) {
  event.preventDefault();
  if (state.sending || state.mode === "loading") {
    return;
  }

  const input = cleanedInput();
  const errors = validate(input);
  if (Object.keys(errors).length > 0) {
    showErrors(errors);
    return;
  }

  clearErrors();
  setSending(true);
  try {
    if (state.mode === "demo") {
      const saved = saveDemoSubmission(input);
      if (saved) {
        acceptSubmission(saved);
      }
      return;
    }
    await postLive(input);
  } finally {
    setSending(false);
  }
}

async function postLive(input) {
  let response;
  try {
    response = await fetch("api.php", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify(input),
    });
  } catch {
    showFormProblem("Could not reach the server. Nothing was saved.");
    return;
  }

  const type = response.headers.get("Content-Type") || "";
  let payload = null;
  if (type.toLowerCase().includes("application/json")) {
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
  }

  if (response.status === 422 && payload && payload.errors && typeof payload.errors === "object") {
    showErrors(payload.errors);
    return;
  }

  if (response.status === 201 && payload && payload.submission) {
    acceptSubmission(payload.submission);
    return;
  }

  showFormProblem("The server did not accept the submission. Nothing was saved.");
}

function saveDemoSubmission(input) {
  const submission = {
    id: randomId(),
    name: input.name,
    regNo: input.regNo,
    title: input.title,
    url: input.url,
    submittedAt: stamp(),
  };
  const next = [submission, ...state.rows];
  if (!writeDemoRows(next)) {
    showFormProblem("This browser blocked local storage, so the submission was not saved.");
    return null;
  }
  state.rows = next;
  return submission;
}

function acceptSubmission(submission) {
  state.rows = [submission, ...state.rows.filter((row) => row.id !== submission.id)];
  state.query = "";
  searchInput.value = "";
  form.reset();
  clearErrors();
  renderList();
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const row = document.getElementById("row-" + submission.id);
  if (row && !reduceMotion) {
    row.classList.add("is-new");
    window.setTimeout(() => row.classList.remove("is-new"), 2400);
  }
  showToast("Submission saved");
}

function cleanedInput() {
  return {
    name: clean(inputs.name.value),
    regNo: clean(inputs.regNo.value),
    title: clean(inputs.title.value),
    url: clean(inputs.url.value),
  };
}

function clean(value) {
  const trimmed = String(value).trim().replace(/\p{C}+/gu, "");
  return trimmed.trim();
}

function charLength(value) {
  return Array.from(value).length;
}

function validate(input) {
  const errors = {};

  if (input.name === "") {
    errors.name = "Full name is required";
  } else if (charLength(input.name) < 2 || charLength(input.name) > 100) {
    errors.name = "Name must be between 2 and 100 characters";
  }

  if (input.regNo === "") {
    errors.regNo = "Registration number is required";
  } else if (!registrationNumberIsValid(input.regNo)) {
    errors.regNo = "Registration number may only contain letters, numbers, dashes and slashes";
  }

  if (input.title === "") {
    errors.title = "Assignment title is required";
  } else if (charLength(input.title) < 3 || charLength(input.title) > 150) {
    errors.title = "Title must be between 3 and 150 characters";
  }

  if (input.url === "") {
    errors.url = "Submission URL is required";
  } else if (!urlIsValid(input.url)) {
    errors.url = URL_MESSAGE;
  }

  return errors;
}

function registrationNumberIsValid(regNo) {
  const length = charLength(regNo);
  if (length < 3 || length > 30) {
    return false;
  }
  return /^[\p{L}\p{N}\/-]+$/u.test(regNo);
}

function urlIsValid(url) {
  if (charLength(url) > 2048 || /\s/.test(url) || !/^https?:\/\//i.test(url)) {
    return false;
  }
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return false;
  }
  const scheme = parsed.protocol.replace(":", "").toLowerCase();
  if (scheme !== "http" && scheme !== "https") {
    return false;
  }
  const host = parsed.hostname;
  if (!host) {
    return false;
  }
  return host.includes(".") || host.toLowerCase() === "localhost";
}

function showErrors(errors) {
  formError.textContent = "";
  let first = null;
  const messages = [];
  for (const field of FIELDS) {
    const message = typeof errors[field] === "string" ? errors[field] : "";
    const node = document.getElementById(field + "-error");
    node.textContent = message;
    inputs[field].setAttribute("aria-invalid", message ? "true" : "false");
    inputs[field].setAttribute(
      "aria-describedby",
      message ? field + "-help " + field + "-error" : field + "-help"
    );
    if (message) {
      messages.push(message);
      if (!first) {
        first = inputs[field];
      }
    }
  }
  formStatus.textContent = messages.join(". ");
  if (first) {
    first.focus();
  }
}

function clearFieldError(field) {
  const node = document.getElementById(field + "-error");
  if (node.textContent === "") {
    return;
  }
  node.textContent = "";
  inputs[field].setAttribute("aria-invalid", "false");
  inputs[field].setAttribute("aria-describedby", field + "-help");
  const remaining = FIELDS
    .map((name) => document.getElementById(name + "-error").textContent)
    .filter(Boolean);
  formStatus.textContent = remaining.join(". ");
}

function clearErrors() {
  formError.textContent = "";
  formStatus.textContent = "";
  for (const field of FIELDS) {
    document.getElementById(field + "-error").textContent = "";
    inputs[field].setAttribute("aria-invalid", "false");
    inputs[field].setAttribute("aria-describedby", field + "-help");
  }
}

function showFormProblem(message) {
  formError.textContent = message;
  formStatus.textContent = message;
}

function setSending(sending) {
  state.sending = sending;
  submitButton.disabled = sending;
  submitButton.setAttribute("aria-busy", sending ? "true" : "false");
  spinner.hidden = !sending;
  submitLabel.textContent = sending ? "Saving" : "Submit assignment";
}

function setStatus(kind) {
  statusPill.classList.remove("is-live", "is-demo", "is-down");
  if (kind === "live") {
    statusLabel.textContent = "Live";
    statusPill.classList.add("is-live");
    return;
  }
  if (kind === "demo") {
    statusLabel.textContent = "Demo mode · saved in this browser";
    statusPill.classList.add("is-demo");
    return;
  }
  statusLabel.textContent = "Unavailable";
  statusPill.classList.add("is-down");
}

function renderList() {
  const loading = state.mode === "loading";
  searchInput.disabled = state.mode !== "live" && state.mode !== "demo";
  if (loading) {
    countChip.textContent = "…";
    countChip.setAttribute("aria-label", "Loading submissions");
    tableWrap.hidden = false;
    listPanel.replaceChildren();
    listBody.replaceChildren(skeletonRow(), skeletonRow(), skeletonRow());
    return;
  }

  if (state.mode === "error") {
    countChip.textContent = "0";
    countChip.setAttribute("aria-label", "Submissions unavailable");
    tableWrap.hidden = true;
    listPanel.replaceChildren(errorState());
    return;
  }

  const matches = filteredRows();
  const total = state.rows.length;
  const filtering = state.query.trim() !== "" && matches.length !== total;
  countChip.textContent = filtering ? matches.length + " of " + total : String(matches.length);
  countChip.setAttribute(
    "aria-label",
    filtering
      ? matches.length + " of " + total + " submissions"
      : (matches.length === 1 ? "1 submission" : matches.length + " submissions")
  );

  if (state.rows.length === 0) {
    tableWrap.hidden = true;
    listPanel.replaceChildren(messageState("No submissions yet", "Submit the form and the assignment will appear in this list."));
    return;
  }

  if (matches.length === 0) {
    tableWrap.hidden = true;
    listPanel.replaceChildren(messageState("No matching submissions", "Try a different name, registration number, or title.", true));
    return;
  }

  tableWrap.hidden = false;
  listPanel.replaceChildren();
  const fragment = document.createDocumentFragment();
  for (const row of matches) {
    fragment.append(submissionRow(row));
  }
  listBody.replaceChildren(fragment);
}

function filteredRows() {
  const query = state.query.trim().toLowerCase();
  const rows = [...state.rows].sort((a, b) => Date.parse(b.submittedAt || 0) - Date.parse(a.submittedAt || 0));
  if (query === "") {
    return rows;
  }
  return rows.filter((row) =>
    [row.name, row.regNo, row.title].some((value) => String(value || "").toLowerCase().includes(query))
  );
}

function skeletonRow() {
  const row = document.createElement("tr");
  row.className = "skeleton";
  for (let index = 0; index < 4; index += 1) {
    const cell = document.createElement("td");
    const bar = document.createElement("span");
    bar.className = index % 2 === 0 ? "skeleton-bar" : "skeleton-bar short";
    cell.append(bar);
    row.append(cell);
  }
  return row;
}

function messageState(title, hint, withSearch) {
  const wrap = document.createElement("div");
  wrap.className = "state";
  if (withSearch) {
    wrap.append(icon("search", "state-icon"));
  }
  wrap.append(element("p", "state-title", title));
  wrap.append(element("p", "state-hint", hint));
  return wrap;
}

function errorState() {
  const wrap = document.createElement("div");
  wrap.className = "state is-error";
  wrap.setAttribute("role", "alert");
  wrap.append(icon("alert", "state-icon"));
  wrap.append(element("p", "state-title", "Could not load submissions"));
  wrap.append(element("p", "state-hint", "The portal could not be reached."));
  const button = document.createElement("button");
  button.type = "button";
  button.id = "retry-button";
  button.dataset.action = "retry";
  button.textContent = "Retry";
  wrap.append(button);
  return wrap;
}

function submissionRow(row) {
  const tr = document.createElement("tr");
  if (/^[A-Za-z0-9_-]+$/.test(String(row.id || ""))) {
    tr.id = "row-" + row.id;
  }

  const student = document.createElement("div");
  student.className = "cell-value";
  student.append(element("span", "student-name", String(row.name || "")));
  student.append(element("span", "student-reg", String(row.regNo || "")));

  const title = element("div", "cell-value assignment-title", String(row.title || ""));
  const link = element("div", "cell-value");
  link.append(linkNode(String(row.url || "")));

  const when = document.createElement("div");
  when.className = "cell-value";
  const time = document.createElement("time");
  const submittedAt = String(row.submittedAt || "");
  if (submittedAt) {
    time.dateTime = submittedAt;
    time.title = exactTime(submittedAt);
    time.textContent = relativeTime(submittedAt);
  } else {
    time.textContent = "Unknown time";
  }
  when.append(time);

  tr.append(cell("Student", student));
  tr.append(cell("Assignment", title));
  tr.append(cell("Link", link));
  tr.append(cell("Submitted", when));
  return tr;
}

function cell(label, value) {
  const td = document.createElement("td");
  td.dataset.label = label;
  td.append(value);
  return td;
}

function linkNode(url) {
  const parsed = safeHttpUrl(url);
  if (!parsed) {
    return element("span", "plain-link", url);
  }
  const anchor = document.createElement("a");
  anchor.className = "external-link";
  anchor.href = parsed.href;
  anchor.target = "_blank";
  anchor.rel = "noopener noreferrer";
  anchor.title = url;
  anchor.setAttribute("aria-label", "Open " + parsed.hostname + " in a new tab");
  const host = document.createElement("span");
  host.textContent = parsed.hostname;
  anchor.append(host, icon("external", "icon"));
  return anchor;
}

function safeHttpUrl(url) {
  try {
    const parsed = new URL(url);
    if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
      return null;
    }
    if (!parsed.hostname) {
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

function relativeTime(iso) {
  const then = Date.parse(iso);
  if (Number.isNaN(then)) {
    return "Unknown time";
  }
  const seconds = Math.round((Date.now() - then) / 1000);
  if (Math.abs(seconds) < 45) {
    return "Just now";
  }
  const minutes = Math.round(seconds / 60);
  if (Math.abs(minutes) < 60) {
    return Math.abs(minutes) === 1 ? "1 min ago" : Math.abs(minutes) + " min ago";
  }
  const hours = Math.round(minutes / 60);
  if (Math.abs(hours) < 24) {
    return Math.abs(hours) === 1 ? "1 hour ago" : Math.abs(hours) + " hours ago";
  }
  const days = Math.round(hours / 24);
  if (Math.abs(days) < 30) {
    return Math.abs(days) === 1 ? "1 day ago" : Math.abs(days) + " days ago";
  }
  return exactTime(iso);
}

function exactTime(iso) {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return iso;
  }
  return new Intl.DateTimeFormat("en-GB", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "UTC",
  }).format(date) + " UTC";
}

function refreshTimes() {
  if (document.visibilityState === "hidden") {
    return;
  }
  document.querySelectorAll("time[datetime]").forEach((node) => {
    node.textContent = relativeTime(node.dateTime);
  });
}

function readDemoRows() {
  let parsed = null;
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw === null || raw.trim() === "" || raw.trim() === "[]") {
      return seedDemoRows();
    }
    parsed = JSON.parse(raw);
  } catch {
    return seedDemoRows();
  }
  if (!Array.isArray(parsed) || parsed.length === 0) {
    return seedDemoRows();
  }
  return parsed.filter((row) => row && typeof row === "object");
}

function seedDemoRows() {
  const rows = [
    demoRow("4f8c1a02b7d64e10", "Amina Qureshi", "FA19-BSE-014", "Cloud Cost Report", "https://github.com/amina-qureshi/cloud-cost", 5 * 60 * 1000),
    demoRow("91ab33c0de7712aa", "Hassan Raza", "SP21-BCS-088", "Container Lab Notes", "https://github.com/hassan-raza/container-lab", 2 * 60 * 60 * 1000),
    demoRow("c0ffee44a91b7701", "Sara Ibrahim", "FA20-BSE-203", "Pipeline Diagram", "https://example.com/sara/pipeline-diagram", 24 * 60 * 60 * 1000),
  ];
  writeDemoRows(rows);
  return rows;
}

function demoRow(id, name, regNo, title, url, ageMs) {
  return {
    id,
    name,
    regNo,
    title,
    url,
    submittedAt: stamp(new Date(Date.now() - ageMs)),
  };
}

function writeDemoRows(rows) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(rows));
    return true;
  } catch {
    return false;
  }
}

function showToast(message) {
  toast.replaceChildren(icon("check", "toast-icon"), element("span", "", message));
  toast.classList.add("is-on");
  window.setTimeout(() => {
    toast.classList.remove("is-on");
    toast.replaceChildren();
  }, 3200);
}

function randomId() {
  const bytes = new Uint8Array(8);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function stamp(date = new Date()) {
  const pad = (value) => String(value).padStart(2, "0");
  return (
    date.getUTCFullYear() +
    "-" + pad(date.getUTCMonth() + 1) +
    "-" + pad(date.getUTCDate()) +
    "T" + pad(date.getUTCHours()) +
    ":" + pad(date.getUTCMinutes()) +
    ":" + pad(date.getUTCSeconds()) +
    "+00:00"
  );
}

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  return node;
}

function icon(name, className) {
  const svg = document.createElementNS(SVG_NS, "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("class", className);
  svg.setAttribute("aria-hidden", "true");
  svg.setAttribute("focusable", "false");
  const common = {
    fill: "none",
    stroke: "currentColor",
    "stroke-width": "1.75",
    "stroke-linecap": "round",
    "stroke-linejoin": "round",
  };
  if (name === "search") {
    const circle = document.createElementNS(SVG_NS, "circle");
    circle.setAttribute("cx", "11");
    circle.setAttribute("cy", "11");
    circle.setAttribute("r", "7");
    apply(circle, common);
    const path = document.createElementNS(SVG_NS, "path");
    path.setAttribute("d", "M16.5 16.5 21 21");
    apply(path, common);
    svg.append(circle, path);
    return svg;
  }
  const path = document.createElementNS(SVG_NS, "path");
  const paths = {
    external: "M14 5h5v5M19 5 10 14M10 5H6a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-4",
    check: "M20 6 9 17l-5-5",
    alert: "M12 9v4M12 17h.01M10.3 4.9 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.9a2 2 0 0 0-3.4 0z",
  };
  path.setAttribute("d", paths[name] || "");
  apply(path, common);
  svg.append(path);
  return svg;
}

function apply(node, attributes) {
  for (const [key, value] of Object.entries(attributes)) {
    node.setAttribute(key, value);
  }
}
