const currentYearElements = document.querySelectorAll("[data-current-year]");

currentYearElements.forEach((element) => {
  element.textContent = String(new Date().getFullYear());
});