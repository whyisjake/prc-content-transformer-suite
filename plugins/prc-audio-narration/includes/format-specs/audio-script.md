# Audio Narration Script Specification

## Output Format

Continuous spoken prose, ready to be read aloud by a text-to-speech engine. No markup of any kind: no Markdown, no HTML, no headings, no bullets, no tables, no footnote markers, no URLs.

The output is a *script*, not a transcript. Someone hearing it should never be aware that it began life as a web page.

## Core Principle

Every element of the source must survive into the audio in some spoken form. Where a visual element cannot be spoken directly — a chart, a table, a figure reference — replace it with prose that conveys the same finding. Convert, never silently drop.

## Rules

1. **Numbers are spoken, not written.**
   - `62%` becomes `62 percent`
   - `$1.2 million` becomes `1.2 million dollars`
   - `1965-2024` becomes `1965 to 2024`
   - `3.5x` becomes `3.5 times`
   - `No. 1` becomes `number one`
   - Leave the digits as digits; write out only the symbols and abbreviations that would otherwise be mispronounced.

2. **Acronyms and abbreviations.** Expand on first use, with the acronym following in parentheses only if it is used again later. `ACS` becomes `the American Community Survey`. Common ones a listener will know — `U.S.`, `AI` — may stay, but write `U.S.` as `the U.S.` where the sentence needs the article to scan.

3. **Headings become spoken transitions.** Do not read a heading as a bare fragment. Fold it into a sentence that signals the turn: a heading `Partisan differences` becomes something like `Turning to partisan differences.` Keep these short.

4. **Charts, figures, and data visualizations.** Replace with one or two sentences stating what the chart shows and its most important values. Never say "the chart below" or "see figure 2" — the listener has no chart and no figure. If the surrounding prose already states the finding, a chart may be reduced to nothing rather than repeated.

5. **Tables.** Convert to spoken comparison. A two-column table of groups and percentages becomes a sentence or short series of sentences: `Among Democrats, 72 percent agreed, compared with 43 percent of Republicans.` Do not enumerate every cell of a large table — lead with the comparison the table exists to make, then the two or three most significant values.

6. **Lists.** Convert to flowing prose using spoken enumeration where it aids comprehension: `first`, `second`, `and finally`. Short lists become a single sentence with commas and `and`.

7. **Links.** Speak the link text only. Never speak a URL, and never say "click here" or "at the following link."

8. **Footnotes, endnotes, and citation markers.** Remove the markers entirely. If a footnote carries a fact essential to understanding the sentence it hangs off, fold that fact into the sentence. Otherwise drop it.

9. **Block quotes.** Attribute in speech before quoting: `As one respondent put it, ...`. Do not read quotation marks aloud.

10. **Images.** Omit, unless the image carries information not present in the surrounding text, in which case describe it in one sentence.

11. **Parentheticals.** Long parenthetical asides do not survive speech well. Promote them into their own sentence or fold them into the main clause.

12. **Sentence length.** Break sentences longer than roughly 40 words. Spoken comprehension falls off a cliff well before written comprehension does.

## Opening and Closing

**Do not write an opening line naming the publication or the title.** One is added automatically from the post's own data before the script is synthesized, so anything you write here would be spoken twice.

Begin directly with the article's first substantive sentence. Ignore the source document's top-level title heading entirely — unlike the other headings, it does not become a transition and does not appear in the script at all.

Close with a short spoken line indicating the piece has ended. Do not add a call to action, a subscription pitch, or any content not present in the source.

## Content Fidelity

- Preserve every statistic, finding, and attribution. The numbers themselves must not change.
- Preserve the original ordering of findings.
- Do not summarize the article. Length should be comparable to the source; this is a rewrite for the ear, not a condensation.
- Do not add analysis, interpretation, framing, or editorial commentary that is not in the source.
- Methodology statements are content: convert them like anything else rather than dropping them.

## Example

Input (Markdown):

```
## Trust in local news

**72%** of Americans say they trust [local news](https://example.com/study) more
than national outlets, according to the ACS.[^1]

| Source   | Trust Level |
|----------|-------------|
| Local    | 72%         |
| National | 43%         |

[^1]: Survey of 10,000 U.S. adults conducted Jan. 3-14, 2026.
```

Output (Audio Script):

```
Turning to trust in local news.

72 percent of Americans say they trust local news more than national outlets,
according to the American Community Survey. That is compared with 43 percent
who say they trust national outlets. These findings come from a survey of
10,000 U.S. adults conducted January 3rd through 14th, 2026.
```

Note what happened in that example: the heading became a transition, the
percentage stayed a numeral but lost its symbol, the acronym expanded, the link
became plain text, the table became a spoken comparison rather than a second
recitation of the same number, and the footnote was folded into the prose
instead of being dropped or marked.
