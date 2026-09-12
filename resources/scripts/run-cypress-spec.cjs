#!/usr/bin/env node

/**
 * Runs a single generated Cypress spec against a real target URL using
 * Cypress's Module API (not the CLI + a reporter) — this avoids needing
 * mochawesome (and its merge step) just to get a machine-readable result
 * for one spec, and returns a clean JSON result object directly.
 *
 * The result is written to <projectDir>/cypress-result.json rather than
 * stdout — Cypress's own terminal reporter writes extensively to stdout
 * during the run, so appending our JSON there would land inside that
 * output instead of standing alone as parseable JSON.
 *
 * Expects a cypress.config.cjs to already exist in projectDir (written by
 * TestExecutionService) — Cypress auto-discovers it from `project`.
 *
 * Usage: node run-cypress-spec.cjs <specFile> <projectDir> [browserPath]
 */

const fs = require('fs');
const path = require('path');
const cypress = require('cypress');

const [, , specFile, projectDir, browserPath] = process.argv;

if (!specFile || !projectDir) {
    console.error('Usage: node run-cypress-spec.cjs <specFile> <projectDir> [browserPath]');
    process.exit(1);
}

const resultFile = path.join(projectDir, 'cypress-result.json');

const options = {
    project: projectDir,
    spec: specFile,
};

if (browserPath) {
    options.browser = browserPath;
}

cypress
    .run(options)
    .then((results) => {
        fs.writeFileSync(resultFile, JSON.stringify(results));
        process.exit(0);
    })
    .catch((err) => {
        fs.writeFileSync(resultFile, JSON.stringify({ failedToStart: true, message: String((err && err.message) || err) }));
        process.exit(1);
    });
