import next from "eslint-config-next";

const config = [
  ...next,
  { ignores: ["demo/**", "examples/**", ".data/**", "scripts/**"] },
  {
    rules: {
      // Les photos viennent de n'importe quel site client (CDN Shopify, WordPress…) : <img> simple, volontairement.
      "@next/next/no-img-element": "off",
    },
  },
];

export default config;
