import "server-only";
import sanitize from "sanitize-html";

/** Texte riche autorisé : gras, italique, liens, paragraphes, listes. Rien qui puisse casser une mise en page. */
export function sanitizeRichText(html: string): string {
  return sanitize(html, {
    allowedTags: ["p", "br", "strong", "b", "em", "i", "a", "ul", "ol", "li"],
    allowedAttributes: { a: ["href", "target", "rel"] },
    allowedSchemes: ["http", "https", "mailto", "tel"],
    transformTags: {
      b: "strong",
      i: "em",
      div: "p",
      a: (tagName, attribs) => ({
        tagName,
        attribs: {
          href: attribs.href ?? "#",
          ...(attribs.target === "_blank" ? { target: "_blank", rel: "noopener noreferrer" } : {}),
        },
      }),
    },
  }).trim();
}
