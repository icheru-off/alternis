/**
 * Analyse d'une page d'offre d'emploi.
 *
 * Deux niveaux :
 *  1. Parseurs dédiés pour les grands sites (LinkedIn, Indeed, WTTJ, HelloWork,
 *     France Travail, APEC, Jobteaser, Monster, Glassdoor, Jooble, La Bonne
 *     Alternance…). Ils lisent le DOM précis de chaque site.
 *  2. Heuristique générique : pour TOUT autre site (y compris le site propre
 *     d'un recruteur), on tente JSON-LD (schema.org JobPosting), puis les
 *     balises Open Graph, puis des repères courants dans le DOM.
 *
 * Objectif : ne jamais bloquer. Même mal reconnu, on renvoie au moins le nom du
 * domaine comme entreprise et le titre de la page comme poste ; l'utilisateur
 * corrige ensuite dans le mini-formulaire.
 */
(function () {
  'use strict';

  const T = (s) => (s == null ? '' : String(s).replace(/\s+/g, ' ').trim());
  const txt = (sel, root) => { const el = (root || document).querySelector(sel); return el ? T(el.textContent) : ''; };
  const attr = (sel, a) => { const el = document.querySelector(sel); return el ? T(el.getAttribute(a)) : ''; };

  /** Nom d'entreprise « propre » depuis un domaine (fallback ultime). */
  function companyFromHost(host) {
    host = (host || location.hostname).replace(/^www\./, '');
    const base = host.split('.')[0] || host;
    return base.charAt(0).toUpperCase() + base.slice(1);
  }

  /** Domaine racine (pour le champ site web). */
  function rootDomain() {
    return location.hostname.replace(/^www\./, '');
  }

  /** Cherche un JobPosting dans les blocs JSON-LD (schema.org). */
  function fromJsonLd() {
    const blocks = document.querySelectorAll('script[type="application/ld+json"]');
    for (const b of blocks) {
      let data;
      try { data = JSON.parse(b.textContent); } catch (e) { continue; }
      const arr = Array.isArray(data) ? data : (data['@graph'] ? data['@graph'] : [data]);
      for (const item of arr) {
        if (!item || typeof item !== 'object') continue;
        const type = item['@type'];
        const isJob = type === 'JobPosting' || (Array.isArray(type) && type.includes('JobPosting'));
        if (!isJob) continue;
        const org = item.hiringOrganization || {};
        const loc = item.jobLocation || {};
        const addr = (Array.isArray(loc) ? (loc[0] || {}) : loc).address || {};
        return {
          name: T(typeof org === 'string' ? org : org.name),
          position: T(item.title),
          city: T(addr.addressLocality || ''),
          website: T(typeof org === 'object' ? (org.sameAs || '') : ''),
          sector: T(item.industry || ''),
          _source: 'jsonld',
        };
      }
    }
    return null;
  }

  /** Nettoie un intitulé de poste (retire H/F, préfixes d'offre, réfs). */
  function cleanPosition(s) {
    s = T(s);
    s = s.replace(/^Offre d['’](?:emploi|alternance|stage)\s+/i, '');
    s = s.replace(/\s*[-–|(]\s*(H\/F|F\/H|H-F|M\/F)\s*[)]?\s*$/i, '');
    s = s.replace(/\s+(H\/F|F\/H|H-F|M\/F)\b/i, '');
    return T(s);
  }

  /** Balises Open Graph / méta génériques. */
  function fromMeta() {
    const ogTitle = attr('meta[property="og:title"]', 'content');
    const ogSite = attr('meta[property="og:site_name"]', 'content');
    const raw = ogTitle || T(document.title);
    if (!raw) return null;

    let position = raw, name = '';

    // Motif fréquent : "... - Recrutement par <ENTREPRISE> | <Site>"
    let m = raw.match(/^(.*?)\s*-\s*Recrutement par\s+(.*?)\s*[|·–-]\s*[^|]*$/i);
    if (m) {
      position = m[1];
      name = m[2];
    } else {
      // "Poste - Entreprise", "Poste chez Entreprise", "Poste @ Entreprise", "Poste | Entreprise"
      const parts = raw.split(/\s+(?:chez|@)\s+|\s+[-–|]\s+/i);
      if (parts.length >= 2) {
        position = parts[0];
        name = parts[parts.length - 1];
        // Si le dernier morceau est le nom du site (ex. "Hellowork"), on l'ignore
        if (ogSite && name && name.toLowerCase() === ogSite.toLowerCase().replace(/^www\./, '')) {
          name = parts.length >= 3 ? parts[parts.length - 2] : '';
        }
      }
    }
    // Nettoyage des préfixes d'offre sur le poste
    position = cleanPosition(position);
    // On retire un éventuel nom de site résiduel dans l'entreprise
    if (name) name = name.replace(/\s*\|\s*.*$/, '').trim();

    return {
      name: name || '',
      position: position,
      city: '',
      website: '',
      _source: 'meta',
    };
  }

  /* ---- Parseurs dédiés ---- */
  const SITES = [
    {
      host: /linkedin\.com/,
      parse() {
        return {
          name: txt('.job-details-jobs-unified-top-card__company-name a, .topcard__org-name-link, .jobs-unified-top-card__company-name'),
          position: txt('.job-details-jobs-unified-top-card__job-title, .topcard__title, h1'),
          city: txt('.job-details-jobs-unified-top-card__bullet, .topcard__flavor--bullet'),
          website: '', _source: 'linkedin',
        };
      },
    },
    {
      host: /indeed\./,
      parse() {
        return {
          name: txt('[data-testid="inlineHeader-companyName"], [data-company-name], .jobsearch-CompanyInfoContainer a'),
          position: txt('h1.jobsearch-JobInfoHeader-title, h1'),
          city: txt('[data-testid="inlineHeader-companyLocation"], [data-testid="job-location"]'),
          website: '', _source: 'indeed',
        };
      },
    },
    {
      host: /welcometothejungle\.com/,
      parse() {
        return {
          name: txt('a[href*="/companies/"] , [data-testid="job-header-organization-title"]'),
          position: txt('h1, [data-testid="job-title"]'),
          city: txt('[data-testid="job-metadata-location"], i[name="location"] + span'),
          website: '', _source: 'wttj',
        };
      },
    },
    {
      host: /hellowork\.com|regionsjob\.com/,
      parse() {
        // L'entreprise est le lien vers /entreprises/... (PAS le menu "Trouver mon entreprise")
        let company = '';
        const compLink = document.querySelector('h1 a[href*="/entreprises/"], a[href*="/fr-fr/entreprises/"]');
        if (compLink) company = T(compLink.textContent);

        // Titre du poste : le h1, en retirant le nom d'entreprise s'il y est collé
        let position = txt('h1');
        if (company && position.endsWith(company)) position = T(position.slice(0, -company.length));

        // Repli via og:title : "Offre d'Alternance <POSTE> <VILLE> - Recrutement par <ENTREPRISE> | Hellowork"
        const og = attr('meta[property="og:title"]', 'content');
        if (og) {
          const m = og.match(/^(?:Offre d['’][^\s]+\s+)?(.*?)\s*-\s*Recrutement par\s+(.*?)\s*\|/i);
          if (m) {
            if (!company) company = T(m[2]);
            if (!position) position = T(m[1]);
          }
        }
        // Ville : puce de localisation
        let city = txt('[data-cy="localisationCard"], .tw-flex .tw-typo-secImportant, li:has(svg) ');
        city = city.replace(/\s*-\s*\d{2,3}\s*$/, '');   // retire " - 94"
        return { name: company, position: position, city: city, website: '', _source: 'hellowork' };
      },
    },
    {
      host: /(candidat\.)?francetravail\.fr|pole-emploi\.fr/,
      parse() {
        return {
          name: txt('.media-body .subtext, [itemprop="hiringOrganization"], .company-name'),
          position: txt('h1[itemprop="title"], h1'),
          city: txt('[itemprop="addressLocality"], .localisation'),
          website: '', _source: 'francetravail',
        };
      },
    },
    {
      host: /apec\.fr/,
      parse() {
        return {
          name: txt('.card-offer__company, .details-offer__company'),
          position: txt('h1'),
          city: txt('.details-offer__location, .card-offer__location'),
          website: '', _source: 'apec',
        };
      },
    },
    {
      host: /jobteaser\.com/,
      parse() {
        return {
          name: txt('[data-testid="company-name"], a[href*="/companies/"]'),
          position: txt('h1'),
          city: txt('[data-testid="job-location"]'),
          website: '', _source: 'jobteaser',
        };
      },
    },
    {
      host: /monster\./,
      parse() {
        return {
          name: txt('[name="companyName"], .company a, [data-testid="companyName"]'),
          position: txt('h1, [data-testid="jobTitle"]'),
          city: txt('[data-testid="jobDetailLocation"], .location'),
          website: '', _source: 'monster',
        };
      },
    },
    {
      host: /glassdoor\./,
      parse() {
        return {
          name: txt('[data-test="employer-name"], .EmployerProfile_employerName__*'),
          position: txt('[data-test="job-title"], h1'),
          city: txt('[data-test="location"]'),
          website: '', _source: 'glassdoor',
        };
      },
    },
    {
      host: /jooble\.org/,
      parse() {
        return {
          name: txt('[class*="company"], .company_name'),
          position: txt('h1'),
          city: txt('[class*="location"], .caption_location'),
          website: '', _source: 'jooble',
        };
      },
    },
    {
      host: /labonnealternance\.|apprentissage\.beta\.gouv\.fr/,
      parse() {
        return {
          name: txt('[data-testid="offer-company"], .company-name, h2'),
          position: txt('h1, [data-testid="offer-title"]'),
          city: txt('[data-testid="offer-location"], .location'),
          website: '', _source: 'lba',
        };
      },
    },
  ];

  /**
   * Point d'entrée : renvoie toujours un objet exploitable.
   * { name, position, city, website, sector, source_url, page_title, supported, source }
   */
  function extract() {
    let out = null;

    // 1) parseur dédié si le site est reconnu
    const site = SITES.find((s) => s.host.test(location.hostname));
    if (site) {
      try { out = site.parse(); } catch (e) { out = null; }
    }

    // 2) JSON-LD (fiable et normalisé) — complète les trous
    const ld = fromJsonLd();
    if (ld) out = Object.assign({}, ld, cleanEmpty(out || {}));

    // 3) heuristique meta si toujours rien de solide
    if (!out || !out.name || !out.position) {
      const meta = fromMeta() || {};
      out = Object.assign({}, meta, cleanEmpty(out || {}));
    }

    out = out || {};

    // Poste : nettoyage systématique (H/F, préfixes d'offre)
    if (out.position) out.position = cleanPosition(out.position);

    // Garde-fous : jamais vide
    if (!out.name) {
      // dernier recours : entreprise depuis og:site_name si ce n'est pas un job board
      const ogSite = attr('meta[property="og:site_name"]', 'content');
      const boards = /hellowork|indeed|linkedin|welcometothejungle|apec|jobteaser|monster|glassdoor|jooble|francetravail|pole-emploi|regionsjob/i;
      if (ogSite && !boards.test(ogSite) && !boards.test(location.hostname)) out.name = ogSite;
      else out.name = companyFromHost();
    }
    if (!out.position) {
      out.position = cleanPosition(T(document.title).split(/\s+[-–|]\s+/)[0] || '');
    }

    // IMPORTANT : le champ "site web" est le domaine de l'ENTREPRISE, jamais
    // l'URL de l'annonce. Sur un job board, on laisse vide (l'utilisateur le
    // complètera) ; sur le site propre d'un recruteur, le domaine convient.
    const boardHost = /hellowork|indeed|linkedin|welcometothejungle|apec|jobteaser|monster|glassdoor|jooble|francetravail|pole-emploi|regionsjob|labonnealternance|apprentissage\.beta\.gouv/i;
    if (!out.website) {
      out.website = boardHost.test(location.hostname) ? '' : rootDomain();
    } else if (/^https?:\/\//i.test(out.website)) {
      // si un parseur a mis une URL complète, on ne garde que le domaine
      try { out.website = new URL(out.website).hostname.replace(/^www\./, ''); } catch (e) {}
    }

    // L'URL de l'annonce va TOUJOURS dans source_url (et pas ailleurs)
    out.source_url = location.href.split('#')[0];
    out.page_title = T(document.title);
    out.supported = !!site || (ld && ld._source === 'jsonld');
    out.source = out._source || (ld ? 'jsonld' : 'meta');
    delete out._source;
    return out;
  }

  /** Retire les champs vides d'un objet (pour les fusions). */
  function cleanEmpty(o) {
    const r = {};
    for (const k in o) if (o[k] !== '' && o[k] != null) r[k] = o[k];
    return r;
  }

  // Expose au content script
  window.__alternisExtract = extract;
})();
