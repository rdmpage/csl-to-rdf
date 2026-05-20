# Design notes

Open design questions encountered while building `csl-to-rdf`. The
[README](README.md) records choices we have already made; this file is for the
problems we are still working through. Add a new section whenever a question
recurs.

## Validation

The shape of the emitted RDF is documented in [`shapes.ttl`](shapes.ttl) as a
set of SHACL shapes. SHACL is the W3C-recommended way to write down what a
valid graph looks like — cardinalities, datatypes, expected types of linked
nodes — separately from the code that generates it.

There is no maintained native PHP SHACL validator, so validation runs out of
process. The standard tool is [pyshacl](https://github.com/RDFLib/pySHACL):

```
pipx install pyshacl
php doi2csl.php dois.txt | pyshacl --allow-warnings -df nt -s shapes.ttl -sf turtle -f human -
```

The converters route debug output to STDERR, so STDOUT is always clean
N-Triples that can be piped straight into the validator. `--allow-warnings`
makes pyshacl exit 0 (and report `Conforms: True`) when the only results are
`sh:Warning`-severity, which is appropriate for the Person warnings noted
below.

For quick iteration on the shapes themselves (no install), the
[Zazuko SHACL Playground](https://shacl-playground.zazuko.com/) accepts
shapes + data in the browser and reports violations instantly.

### Calibration notes

On a 200-DOI random slice of the cache (~17k triples), the only violations
are `:PersonShape` flagging Person nodes where Crossref/Zenodo packed the
full name into `family` with no `given` — including the CSL `"Jr."` suffix
case. These are upstream-data anomalies, not converter bugs, so the shape is
declared `sh:severity sh:Warning`: pyshacl still reports them, but they no
longer cause `Conforms: False`. A converter-side heuristic that detects
`family` containing a space (or a known suffix) and downgrades to
`schema:name` is the obvious follow-up.

## Identity for nodes without a global identifier

### The problem

Several entities in CSL-JSON have no globally-resolvable identifier:

- **Funders** without a Crossref Funder Registry DOI
- **Grants** — never have a DOI; only an award string scoped to a funder
- **Publishers** when CSL gives us a string (most of the time)
- **The `MediaObject`** that carries the CSL-JSON `schema:encoding`
- **Authors** without an ORCID
- **Cited works** without a DOI (when `allcitations` is enabled)

Today these are all minted as blank nodes with a fresh CUID per run
(`shared.php`, e.g. the `_:` + `$cuid->cuid()` pattern). That works for a
single emit-and-load pass, but has costs:

- **Not idempotent.** Re-running over the same DOI produces a different graph.
  Diffing two runs or doing incremental updates against `registry.rdf` is
  noisy: every grant, every publisher, every encoding looks "new" even when
  nothing changed.
- **No cross-work joins.** Two works funded by the same funder-without-DOI get
  two distinct blank nodes. We can never say "show all works funded by NSF
  programmes that lack a Funder Registry DOI" because the blank nodes do not
  unify across works.
- **Loader-dependent semantics.** Some triplestores rewrite blank node labels
  on load, which makes downstream debugging harder.

### Options

1. **Status quo — fresh blank node per run, full replace.**
   Simple. Acceptable if the workflow is always "delete the named graph for
   this DOI, reinsert". Falls over the moment we want incremental updates or
   cross-work analysis on these entities.

2. **Skolemize: mint deterministic IRIs.**
   Replace blank nodes with IRIs derived by hashing the content that makes the
   entity unique. RDF 1.1 reserves
   `https://w3id.org/<our-namespace>/.well-known/genid/<hash>` for exactly
   this. Suggested keying:

   | Entity | Keying inputs |
   |---|---|
   | Funder (no DOI) | normalized funder name |
   | Grant | funder identifier + award string |
   | Publisher (string) | normalized publisher name |
   | CSL encoding `MediaObject` | parent work IRI (one encoding per work) |
   | Author (no ORCID) | parent work IRI + position + given + family |
   | Cited work (no DOI) | parent work IRI + `reference.key` if present, else hash of `unstructured` |

   Pros: stable across runs, joinable across works when keying is global
   (funders, publishers), idempotent.
   Cons: choice of keying inputs is load-bearing — too narrow and we collide
   different entities; too wide and we re-fragment them. Author identity is
   the worst case here: same name does not mean same person, so any cross-work
   keying is a guess.

3. **Persistent CUID mapping.**
   Keep CUIDs, but store a sidecar file mapping `content_hash -> cuid` so
   re-runs reuse the same blank node label. Solves idempotency within our
   pipeline but not cross-work joins, and adds a stateful artifact to manage.

4. **Hybrid.** Skolemize entities where cross-work identity is plausibly
   meaningful (funders, publishers, grants). Keep blank nodes — but mint them
   deterministically from work IRI + position — for entities where it is not
   (authors without ORCID, citations without DOI, encoding MediaObject). This
   buys idempotency everywhere and cross-work joins where they are defensible.

### Current leaning

Option 4, but the keying details need to be pinned down before we commit. In
particular: do we trust normalized publisher/funder names enough to merge
records across works, or do we want a human-curated alias list first?

## Authorship

### The problem

CSL gives us an ordered list of authors with optional `given`, `family`,
`literal`, and `ORCID`. RDF/schema.org gives us several ways to express this,
none of them perfectly:

- `schema:creator` vs `schema:author` — schema.org allows both. `author` is
  more specific to bibliographic works; `creator` is the broader Dublin Core
  lineage. We currently emit `schema:creator`.
- **Order is not preserved.** Multiple `schema:creator` triples are an
  unordered set in RDF. Citation rendering needs order.
- **Author identity** is the same problem as the blank-node section above:
  same name across two works is not the same person.

### Options for order

1. **`schema:position` on each Person node.**
   schema.org's recommended pattern. Each author gets `schema:position "1"^^xsd:integer`.
   SPARQL can `ORDER BY ?position`. Trivial to emit. The cost is that a naive
   consumer who reads `?work schema:creator ?p` without sorting will get
   authors in arbitrary order.

2. **`rdf:List` (`rdf:first` / `rdf:rest` / `rdf:nil`).**
   Proper RDF for ordered sequences. Hostile to SPARQL — you cannot ask "give
   me the third author" without walking the list. Also creates a chain of
   blank nodes, which compounds the identity problem above.

3. **`rdf:Seq` with `rdf:_1`, `rdf:_2`, …**
   The old-school RDF container. Works, but readers rarely handle it.

4. **A `Role` indirection** (schema.org `Role` pattern).
   `work --author--> [Role roleName "author"; position 1] --author--> Person`.
   Verbose, but lets us attach per-work facts to a person (affiliation at time
   of writing, corresponding author flag, etc.) without polluting the Person
   node.

### Current leaning

Switch from `schema:creator` to `schema:author` and add `schema:position`
(option 1). Reserve the `Role` pattern (option 4) for the day we start
ingesting affiliations or corresponding-author flags.

### Identity, again

If we adopt the skolemization scheme from the previous section, an author
without an ORCID becomes an IRI keyed on `(work, position, name)`. That means
the same author appearing on two different works is two different IRIs.
That is wrong in principle but defensible in practice: we cannot prove they
are the same person without an ORCID, and silently merging by name is worse
than silently splitting.

## Open questions

Append new sections here as they come up. Suggested format: **Problem**,
**Options**, **Current leaning**.
