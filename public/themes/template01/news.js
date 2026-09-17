const ARTICLES_ENDPOINT = "/api/articles";
const ARTICLE_SOURCES = new Set(["latest", "featured", "hot", "category"]);
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const REQUEST_TIMEOUT_MS = 12000;

export class ArticlesRequestError extends Error {
  constructor(message, { code = "error", status = 0 } = {}) {
    super(message);
    this.name = "ArticlesRequestError";
    this.code = code;
    this.status = status;
  }
}

function toPositiveInteger(value, fallback, maximum) {
  const parsed = Number.parseInt(String(value), 10);
  if (!Number.isInteger(parsed) || parsed < 1) return fallback;
  return Math.min(parsed, maximum);
}

function normalizeSource(source) {
  return ARTICLE_SOURCES.has(source) ? source : "latest";
}

export function buildArticlesUrl({ source = "latest", categorySlug = "", page = 1, limit = 6 } = {}) {
  const normalizedSource = normalizeSource(source);
  const params = new URLSearchParams({
    source: normalizedSource,
    page: String(toPositiveInteger(page, 1, 100000)),
    limit: String(toPositiveInteger(limit, 6, 24))
  });
  const normalizedCategory = String(categorySlug || "").trim();
  if (normalizedSource === "category") {
    if (!SLUG_PATTERN.test(normalizedCategory) || normalizedCategory.length > 100) {
      throw new ArticlesRequestError("资讯分类参数无效。", { code: "invalid_query" });
    }
    params.set("category_slug", normalizedCategory);
  }
  return `${ARTICLES_ENDPOINT}?${params.toString()}`;
}

async function readJson(response) {
  try {
    return await response.json();
  } catch {
    throw new ArticlesRequestError("资讯接口返回了无法读取的内容。", {
      code: "invalid_response",
      status: response.status
    });
  }
}

function resolveFetch(fetchImpl) {
  const request = fetchImpl || globalThis.fetch;
  if (typeof request !== "function") {
    throw new ArticlesRequestError("当前环境无法加载资讯内容。", { code: "unavailable" });
  }
  return request;
}

async function requestEndpoint(url, { signal, fetchImpl } = {}) {
  const request = resolveFetch(fetchImpl);
  const controller = new AbortController();
  const forwardAbort = () => controller.abort(signal?.reason);
  if (signal?.aborted) forwardAbort();
  else signal?.addEventListener("abort", forwardAbort, { once: true });

  let timedOut = false;
  const timeoutId = globalThis.setTimeout(() => {
    timedOut = true;
    controller.abort(new DOMException("资讯请求超时。", "TimeoutError"));
  }, REQUEST_TIMEOUT_MS);

  try {
    return await request(url, {
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      signal: controller.signal
    });
  } catch (error) {
    if (timedOut) {
      throw new ArticlesRequestError("资讯内容暂时无法加载。", { code: "timeout" });
    }
    throw error;
  } finally {
    globalThis.clearTimeout(timeoutId);
    signal?.removeEventListener("abort", forwardAbort);
  }
}

function isValidDateTime(value) {
  if (typeof value !== "string") return false;
  const match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]+)?(?:Z|[+-]([0-9]{2}):([0-9]{2}))$/.exec(value);
  if (!match) return false;
  const [, yearText, monthText, dayText, hourText, minuteText, secondText, offsetHour, offsetMinute] = match;
  const year = Number(yearText);
  const month = Number(monthText);
  const leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
  const daysInMonth = [31, leapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  return month >= 1 && month <= 12 &&
    Number(dayText) >= 1 && Number(dayText) <= daysInMonth[month - 1] &&
    Number(hourText) <= 23 && Number(minuteText) <= 59 && Number(secondText) <= 59 &&
    (offsetHour === undefined || (Number(offsetHour) <= 23 && Number(offsetMinute) <= 59)) &&
    !Number.isNaN(new Date(value).getTime());
}

function isNonEmptyText(value, maximum) {
  return typeof value === "string" && value.trim().length > 0 && value.length <= maximum;
}

function isValidImage(image) {
  if (image === undefined) return true;
  if (!image || typeof image !== "object") return false;
  if (typeof image.src !== "string" || image.src.trim().length === 0) return false;
  try {
    const url = new URL(image.src, "https://site.invalid/");
    if (url.protocol !== "http:" && url.protocol !== "https:") return false;
  } catch {
    return false;
  }
  if (typeof image.alt !== "string" || image.alt.length > 240) return false;
  if (image.width !== undefined && (!Number.isInteger(image.width) || image.width < 1)) return false;
  if (image.height !== undefined && (!Number.isInteger(image.height) || image.height < 1)) return false;
  return true;
}

function isValidArticleSummary(article) {
  return Boolean(
    article &&
    typeof article === "object" &&
    typeof article.slug === "string" &&
    SLUG_PATTERN.test(article.slug) &&
    article.slug.length <= 160 &&
    isNonEmptyText(article.title, 160) &&
    isNonEmptyText(article.summary, 500) &&
    article.category &&
    typeof article.category === "object" &&
    typeof article.category.slug === "string" &&
    SLUG_PATTERN.test(article.category.slug) &&
    article.category.slug.length <= 100 &&
    isNonEmptyText(article.category.name, 80) &&
    isValidDateTime(article.published_at) &&
    isValidImage(article.cover_image)
  );
}

function isValidPagination(pagination) {
  return Boolean(
    pagination &&
    Number.isInteger(pagination.page) && pagination.page >= 1 &&
    Number.isInteger(pagination.limit) && pagination.limit >= 1 && pagination.limit <= 24 &&
    Number.isInteger(pagination.total) && pagination.total >= 0 &&
    Number.isInteger(pagination.total_pages) && pagination.total_pages >= 0 &&
    typeof pagination.has_previous === "boolean" &&
    typeof pagination.has_next === "boolean"
  );
}

export async function fetchArticles(options = {}, { signal, fetchImpl } = {}) {
  const response = await requestEndpoint(buildArticlesUrl(options), { signal, fetchImpl });
  if (!response.ok) {
    throw new ArticlesRequestError("资讯内容暂时无法加载。", {
      code: "error",
      status: response.status
    });
  }

  const payload = await readJson(response);
  if (
    payload?.status !== "success" ||
    !Array.isArray(payload?.data?.items) ||
    !payload.data.items.every(isValidArticleSummary) ||
    !isValidPagination(payload?.data?.pagination)
  ) {
    throw new ArticlesRequestError("资讯接口返回了无效数据。", {
      code: "invalid_response",
      status: response.status
    });
  }
  return {
    items: payload.data.items,
    pagination: payload.data.pagination ?? {}
  };
}

export async function fetchArticle(slug, { signal, fetchImpl } = {}) {
  const normalizedSlug = String(slug || "").trim();
  if (!SLUG_PATTERN.test(normalizedSlug) || normalizedSlug.length > 160) {
    return { state: "not_found", article: null };
  }

  const response = await requestEndpoint(`${ARTICLES_ENDPOINT}/${encodeURIComponent(normalizedSlug)}`, { signal, fetchImpl });
  if (response.status === 404) return { state: "not_found", article: null };
  if (!response.ok) {
    throw new ArticlesRequestError("资讯详情暂时无法加载。", {
      code: "error",
      status: response.status
    });
  }

  const payload = await readJson(response);
  if (
    payload?.status !== "success" ||
    !isValidArticleSummary(payload?.data?.article) ||
    typeof payload.data.article.content_html !== "string" ||
    payload.data.article.content_html.trim().length === 0 ||
    payload.data.article.content_html.length > 200000 ||
    (payload.data.article.updated_at !== undefined && !isValidDateTime(payload.data.article.updated_at))
  ) {
    throw new ArticlesRequestError("资讯接口返回了无效数据。", {
      code: "invalid_response",
      status: response.status
    });
  }
  return { state: "success", article: payload.data.article };
}

export function articlePageHref(slug) {
  return `/article/${encodeURIComponent(String(slug || "").trim())}`;
}

export function resolveArticleListOptions(dataset = {}, search = "") {
  const query = new URLSearchParams(search);
  const readFromQuery = dataset.newsSourceFromQuery === "true";
  const source = normalizeSource(
    readFromQuery ? query.get("source") || dataset.newsSource : dataset.newsSource
  );
  const categorySlug = source === "category"
    ? String(
        (readFromQuery ? query.get("category_slug") : "") ||
        dataset.newsCategory ||
        ""
      ).trim()
    : "";

  return {
    source,
    categorySlug,
    page: toPositiveInteger(
      readFromQuery ? query.get("page") || dataset.newsPage : dataset.newsPage,
      1,
      100000
    ),
    limit: toPositiveInteger(dataset.newsLimit, 6, 24)
  };
}

export function articleDocumentTitle(title, brandName) {
  const normalizedTitle = String(title || "").trim();
  const normalizedBrand = String(brandName || "").trim();
  return normalizedBrand ? `${normalizedTitle} | ${normalizedBrand}` : normalizedTitle;
}

function formatPublishedDate(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return new Intl.DateTimeFormat("zh-CN", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit"
  }).format(date);
}

function getCategory(article) {
  if (article?.category && typeof article.category === "object") {
    return {
      name: String(article.category.name || "").trim(),
      slug: String(article.category.slug || "").trim()
    };
  }
  return {
    name: String(article?.category_name || article?.category || "").trim(),
    slug: String(article?.category_slug || "").trim()
  };
}

function getCoverImage(article) {
  const image = article?.cover_image || article?.image;
  if (!image || typeof image !== "object" || !image.src) return null;
  return image;
}

function isSafeUrl(value, { allowRelative = true } = {}) {
  try {
    const url = new URL(value, window.location.href);
    if (!allowRelative && url.origin === window.location.origin) return false;
    return url.protocol === "http:" || url.protocol === "https:";
  } catch {
    return false;
  }
}

const SAFE_CONTENT_ELEMENTS = new Set([
  "P", "H2", "H3", "H4", "UL", "OL", "LI", "BLOCKQUOTE", "STRONG", "EM",
  "A", "FIGURE", "IMG", "FIGCAPTION", "BR", "HR", "PRE", "CODE"
]);
const DROP_CONTENT_ELEMENTS = new Set([
  "SCRIPT", "STYLE", "TEMPLATE", "NOSCRIPT", "IFRAME", "OBJECT", "EMBED", "FORM"
]);

function appendSanitizedNode(target, node) {
  if (node.nodeType === Node.TEXT_NODE) {
    target.append(document.createTextNode(node.textContent || ""));
    return;
  }
  if (node.nodeType !== Node.ELEMENT_NODE) return;
  if (DROP_CONTENT_ELEMENTS.has(node.tagName)) return;
  if (!SAFE_CONTENT_ELEMENTS.has(node.tagName)) {
    [...node.childNodes].forEach((child) => appendSanitizedNode(target, child));
    return;
  }

  const element = document.createElement(node.tagName.toLowerCase());
  if (node.tagName === "A") {
    const href = node.getAttribute("href") || "";
    if (isSafeUrl(href)) {
      element.setAttribute("href", href);
      if (new URL(href, window.location.href).origin !== window.location.origin) {
        element.setAttribute("target", "_blank");
        element.setAttribute("rel", "noopener noreferrer");
      }
    }
  }
  if (node.tagName === "IMG") {
    const src = node.getAttribute("src") || "";
    if (!isSafeUrl(src)) return;
    element.setAttribute("src", src);
    element.setAttribute("alt", node.getAttribute("alt") || "");
    element.setAttribute("loading", "lazy");
    for (const attribute of ["width", "height"]) {
      const value = Number.parseInt(node.getAttribute(attribute) || "", 10);
      if (Number.isInteger(value) && value > 0) element.setAttribute(attribute, String(value));
    }
  }
  [...node.childNodes].forEach((child) => appendSanitizedNode(element, child));
  target.append(element);
}

function appendSafeContent(host, markup) {
  const template = document.createElement("template");
  template.innerHTML = markup;
  [...template.content.childNodes].forEach((node) => appendSanitizedNode(host, node));
}

function createArticleCard(article) {
  const card = document.createElement("article");
  card.className = "article-card";
  card.dataset.pointerCard = "";
  card.addEventListener("pointermove", (event) => {
    const rect = card.getBoundingClientRect();
    card.style.setProperty("--pointer-x", `${event.clientX - rect.left}px`);
    card.style.setProperty("--pointer-y", `${event.clientY - rect.top}px`);
  });

  const link = document.createElement("a");
  link.className = "article-card-link";
  link.href = articlePageHref(article.slug);
  link.setAttribute("aria-label", `阅读资讯：${article.title || "资讯详情"}`);

  const visual = document.createElement("div");
  visual.className = "article-image";
  const image = getCoverImage(article);
  if (image && isSafeUrl(image.src)) {
    const element = document.createElement("img");
    element.src = image.src;
    element.alt = String(image.alt || "");
    element.loading = "lazy";
    if (Number.isFinite(Number(image.width))) element.width = Number(image.width);
    if (Number.isFinite(Number(image.height))) element.height = Number(image.height);
    visual.append(element);
  } else {
    visual.classList.add("article-image-placeholder");
    const placeholder = document.createElement("span");
    placeholder.textContent = "资讯";
    visual.append(placeholder);
  }

  const category = getCategory(article);
  if (category.name) {
    const tag = document.createElement("span");
    tag.className = "article-tag";
    tag.textContent = category.name;
    visual.append(tag);
  }

  const content = document.createElement("div");
  content.className = "article-body";
  const published = formatPublishedDate(article.published_at);
  if (published) {
    const time = document.createElement("time");
    time.dateTime = article.published_at;
    time.textContent = published;
    content.append(time);
  }
  const title = document.createElement("h3");
  title.textContent = String(article.title || "资讯详情");
  const summary = document.createElement("p");
  summary.textContent = String(article.summary || "文章摘要暂未提供。");
  const action = document.createElement("span");
  action.className = "text-link article-action";
  action.textContent = "阅读全文 ↗";
  content.append(title, summary, action);
  link.append(visual, content);
  card.append(link);
  return card;
}

function createStatus(state, message, onRetry) {
  const panel = document.createElement("div");
  panel.className = "news-state";
  panel.dataset.state = state;
  const title = document.createElement("strong");
  title.textContent = message;
  panel.append(title);
  if (onRetry) {
    const button = document.createElement("button");
    button.className = "button button-outline-dark";
    button.type = "button";
    button.textContent = "重新加载";
    button.addEventListener("click", onRetry, { once: true });
    panel.append(button);
  }
  return panel;
}

const listControllers = new WeakMap();
const activeControllers = new Set();

if (typeof window !== "undefined") {
  window.addEventListener("pagehide", () => {
    activeControllers.forEach((controller) => controller.abort());
    activeControllers.clear();
  }, { once: true });
}

async function loadArticleList(host, options) {
  listControllers.get(host)?.abort();
  const controller = new AbortController();
  listControllers.set(host, controller);
  activeControllers.add(controller);
  host.dataset.state = "loading";
  host.setAttribute("aria-busy", "true");
  host.dispatchEvent(new CustomEvent("articlesloading"));
  host.replaceChildren(createStatus("loading", "正在加载资讯内容。"));

  try {
    const result = await fetchArticles(options, { signal: controller.signal });
    if (controller.signal.aborted) return;
    if (result.items.length === 0) {
      host.dataset.state = "empty";
      host.replaceChildren(createStatus("empty", "暂无资讯内容。"));
    } else {
      host.dataset.state = "success";
      host.replaceChildren(...result.items.map(createArticleCard));
    }
    host.setAttribute("aria-busy", "false");
    host.dispatchEvent(new CustomEvent("articlesloaded", { detail: result }));
  } catch (error) {
    if (controller.signal.aborted) return;
    host.dataset.state = "error";
    host.setAttribute("aria-busy", "false");
    host.replaceChildren(createStatus("error", "资讯内容暂时无法显示，请稍后重试。", () => loadArticleList(host, options)));
  } finally {
    activeControllers.delete(controller);
  }
}

function initializeArticleLists() {
  document.querySelectorAll("[data-news-list]").forEach((host) => {
    const options = resolveArticleListOptions(host.dataset, window.location.search);
    const readFromQuery = host.dataset.newsSourceFromQuery === "true";
    const previous = document.querySelector("[data-news-previous]");
    const next = document.querySelector("[data-news-next]");
    const label = document.querySelector("[data-news-page-label]");

    document.querySelectorAll("[data-news-filter]").forEach((filter) => {
      const active = filter.dataset.newsFilter === options.source;
      filter.classList.toggle("is-active", active);
      filter.setAttribute("aria-pressed", String(active));
      filter.addEventListener("click", () => {
        const nextSource = normalizeSource(filter.dataset.newsFilter);
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set("source", nextSource);
        nextUrl.searchParams.delete("page");
        nextUrl.searchParams.delete("category_slug");
        window.history.replaceState(null, "", nextUrl);
        document.querySelectorAll("[data-news-filter]").forEach((item) => {
          const selected = item === filter;
          item.classList.toggle("is-active", selected);
          item.setAttribute("aria-pressed", String(selected));
        });
        options.source = nextSource;
        options.categorySlug = "";
        options.page = 1;
        loadArticleList(host, options);
      });
    });

    host.addEventListener("articlesloading", () => {
      if (previous instanceof HTMLButtonElement) previous.disabled = true;
      if (next instanceof HTMLButtonElement) next.disabled = true;
      if (label) label.textContent = `第 ${options.page} 页`;
    });

    host.addEventListener("articlesloaded", (event) => {
      const pagination = event.detail?.pagination || {};
      const currentPage = toPositiveInteger(pagination.page || options.page, options.page, 100000);
      const totalPages = toPositiveInteger(pagination.total_pages, currentPage, 100000);
      options.page = currentPage;
      if (previous instanceof HTMLButtonElement) previous.disabled = currentPage <= 1;
      if (next instanceof HTMLButtonElement) {
        const hasNext = typeof pagination.has_next === "boolean" ? pagination.has_next : currentPage < totalPages;
        next.disabled = !hasNext;
      }
      if (label) label.textContent = `第 ${currentPage} 页`;
    });

    const changePage = (delta) => {
      options.page = Math.max(1, options.page + delta);
      if (readFromQuery) {
        const nextUrl = new URL(window.location.href);
        if (options.page === 1) nextUrl.searchParams.delete("page");
        else nextUrl.searchParams.set("page", String(options.page));
        window.history.replaceState(null, "", nextUrl);
      }
      loadArticleList(host, options);
      const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches;
      host.scrollIntoView({ behavior: reducedMotion ? "auto" : "smooth", block: "start" });
    };
    document.querySelector("[data-news-previous]")?.addEventListener("click", () => changePage(-1));
    document.querySelector("[data-news-next]")?.addEventListener("click", () => changePage(1));
    loadArticleList(host, options);
  });
}

function renderArticleDetail(root, article) {
  const title = String(article.title || "资讯详情");
  const summary = String(article.summary || "");
  const category = getCategory(article);
  const published = formatPublishedDate(article.published_at);
  root.dataset.state = "success";
  root.querySelector("[data-article-title]").textContent = title;
  const summaryNode = root.querySelector("[data-article-summary]");
  summaryNode.textContent = summary || "文章摘要暂未提供。";
  const categoryNode = root.querySelector("[data-article-category]");
  categoryNode.textContent = category.name || "资讯";
  const dateNode = root.querySelector("[data-article-date]");
  dateNode.textContent = published;
  dateNode.dateTime = article.published_at || "";
  dateNode.hidden = !published;
  const bodyNode = root.querySelector("[data-article-content]");
  bodyNode.replaceChildren();
  appendSafeContent(bodyNode, article.content_html);

  const image = getCoverImage(article);
  const figure = root.querySelector("[data-article-cover]");
  if (figure && image && isSafeUrl(image.src)) {
    const element = figure.querySelector("img");
    element.src = image.src;
    element.alt = String(image.alt || "");
    if (Number.isFinite(Number(image.width))) element.width = Number(image.width);
    if (Number.isFinite(Number(image.height))) element.height = Number(image.height);
    figure.hidden = false;
  } else if (figure) {
    figure.hidden = true;
  }

  const brandName = document.querySelector('[data-content="site.brand_name"]')?.textContent || "";
  const pageTitle = articleDocumentTitle(title, brandName);
  document.title = pageTitle;
  const description = document.querySelector('meta[name="description"]');
  if (description && summary) description.setAttribute("content", summary);
  const socialTitle = document.querySelector('meta[property="og:title"]');
  if (socialTitle) socialTitle.setAttribute("content", pageTitle);
  const socialDescription = document.querySelector('meta[property="og:description"]');
  if (socialDescription && summary) socialDescription.setAttribute("content", summary);
}

function initializeArticleDetail() {
  const root = document.querySelector("[data-article-detail]");
  if (!root) return;
  const slug = new URLSearchParams(window.location.search).get("slug") || "";
  const status = root.querySelector("[data-article-status]");
  let activeController = null;
  root.dataset.state = "loading";
  root.setAttribute("aria-busy", "true");
  status.textContent = "正在加载资讯详情。";

  const load = async () => {
    activeController?.abort();
    const controller = new AbortController();
    activeController = controller;
    activeControllers.add(controller);
    root.dataset.state = "loading";
    root.setAttribute("aria-busy", "true");
    status.textContent = "正在加载资讯详情。";
    try {
      const result = await fetchArticle(slug, { signal: controller.signal });
      if (result.state === "not_found") {
        root.dataset.state = "not_found";
        root.setAttribute("aria-busy", "false");
        status.textContent = "未找到这篇资讯。";
        return;
      }
      renderArticleDetail(root, result.article);
      root.setAttribute("aria-busy", "false");
      status.textContent = "";
    } catch {
      if (controller.signal.aborted) return;
      root.dataset.state = "error";
      root.setAttribute("aria-busy", "false");
      status.replaceChildren();
      const message = document.createElement("span");
      message.textContent = "资讯详情暂时无法显示，请稍后重试。";
      const button = document.createElement("button");
      button.type = "button";
      button.className = "text-button";
      button.textContent = "重新加载";
      button.addEventListener("click", load, { once: true });
      status.append(message, button);
    } finally {
      activeControllers.delete(controller);
      if (activeController === controller) activeController = null;
    }
  };
  load();
}

export function initNews() {
  initializeArticleLists();
  initializeArticleDetail();
}
