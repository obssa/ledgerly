import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    // An effect's return value is its cleanup, called when the page changes. A one-line arrow returns whatever its
    // call returns (scrollIntoView now returns a promise), and React then crashes calling it: "i is not a function".
    rules: {
      "no-restricted-syntax": ["error",
        {
          selector: "CallExpression[callee.name=/^use(Layout|Insertion)?Effect$/] > ArrowFunctionExpression[expression=true]",
          message: "Give the effect a { block } body: a one-line arrow returns its call's value, which React then calls as the cleanup.",
        },
        {
          selector: "CallExpression[callee.name=/^use(Layout|Insertion)?Effect$/] > Identifier:first-child",
          message: "Wrap the function in () => { … }: whatever it returns becomes the effect's cleanup.",
        },
      ],
    },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
