/**
 * Service worker de l'extension.
 * Rôle minimal : garder l'icône réactive. Toute la logique réseau se fait dans
 * le popup (qui a accès au stockage et à l'onglet actif).
 */
const api = (typeof browser !== 'undefined') ? browser : chrome;

api.runtime.onInstalled.addListener(() => {
  // Rien de particulier au démarrage ; réservé aux évolutions futures.
});
