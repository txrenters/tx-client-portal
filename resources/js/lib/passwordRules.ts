/**
 * Turn Laravel's `passwordrules` string (Password::toPasswordRulesString(),
 * e.g. "minlength: 12; required: lower; required: upper; required: digit;")
 * into a sentence a person can read under a password field.
 *
 * The raw string still belongs on the input's `passwordrules` attribute, where
 * password managers read it.
 */
export function describePasswordRules(rules: string): string {
    const values = rules
        .split(';')
        .map((rule) => rule.trim())
        .filter((rule) => rule !== '')
        .map((rule) => rule.split(':').map((part) => part.trim()));

    const minimumLength = values.find(([key]) => key === 'minlength')?.[1];
    const required = new Set(
        values.filter(([key]) => key === 'required').map(([, value]) => value),
    );

    const needs: string[] = [];

    if (required.has('upper') && required.has('lower')) {
        needs.push('upper and lower case letters');
    } else if (required.has('lower') || required.has('upper')) {
        needs.push('a letter');
    }

    if (required.has('digit')) {
        needs.push('a number');
    }

    if (required.has('special')) {
        needs.push('a symbol');
    }

    const length = minimumLength
        ? `At least ${minimumLength} characters`
        : 'Choose a password';

    if (needs.length === 0) {
        return `${length}.`;
    }

    const list =
        needs.length === 1
            ? needs[0]
            : `${needs.slice(0, -1).join(', ')} and ${needs.at(-1)}`;

    return `${length}, with ${list}.`;
}
