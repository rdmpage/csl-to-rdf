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

### Context: the graph is published

This section assumes the output is loaded into a triplestore that is **online
as part of a larger biodiversity knowledge graph, behind SPARQL and MCP
interfaces**. That assumption drives most of what follows, and it changed
several earlier conclusions (recorded under "Reversals" below). The short
version: IRIs stop being an internal implementation detail and become a public
interface that other people query, cache, federate against, and link to.

### The problem

Several entities in CSL-JSON have no globally-resolvable identifier:

- **Funders** without a Crossref Funder Registry DOI
- **Grants** — the award string is usually all CSL gives us
- **Publishers** when CSL gives us a string (most of the time)
- **The `MediaObject`** that carries the CSL-JSON `schema:encoding`
- **Authors** without an ORCID
- **Cited works** without a DOI (when `allcitations` is enabled)

Originally these were all minted as blank nodes with a fresh CUID per run
(`shared.php`, e.g. the `_:` + `$cuid->cuid()` pattern). Authors now get
work-scoped IRIs (`person_iri()`); the others are still blank nodes. Behind a public
endpoint that is not merely inelegant, it is broken:

- **Blank node labels are not addressable.** They are scoped to a single result
  set, so a client cannot take `_:b0` from one query and use it in the next.
  `DESCRIBE`, federated `SERVICE` queries, and anything that round-trips a
  result through another system all fail on them. An MCP agent's natural loop —
  query, then ask a follow-up about a node in the result — is exactly the
  pattern blank nodes prevent.
- **Not idempotent.** Re-running over the same DOI produces a different graph,
  so incremental updates and diffs against `registry.rdf` are pure noise.
- **No joins.** Two works funded by the same funder-without-DOI get two
  distinct blank nodes that never unify.
- **Duplicates within a single work.** This is the decisive one. The same DOI
  is described by *multiple sources* (Crossref, OpenCitations, Zenodo…). If
  each source's rendering of an author without an ORCID mints a fresh node, one
  paper ends up with its author list duplicated once per source.

So: **every node gets an IRI.** That part is settled; the remaining questions
are what namespace, and what the IRIs are keyed on.

### Namespace

Minted IRIs take the form:

```
https://w3id.org/<kg>/id/<type>/<hash>
```

where `<type>` is `person`, `organization`, `grant`, `encoding`, `work`, and
`<kg>` is the knowledge graph's namespace — **not** `csl-to-rdf`. See "grain"
below.

- **Register the namespace on `w3id.org`.** It is a redirect service run by the
  W3C Permanent Identifier Community Group: the whole thing is a public GitHub
  repo (`perma-id/w3id.org`) where each namespace is a directory containing an
  `.htaccess`. You open a PR, and once merged you control the redirect target
  forever. The point is the indirection — the IRI survives hosting moves and
  lapsed domains. An *unregistered* w3id path resolves to nothing, so this is a
  real task, not a naming convention.
- **Dereferencing is nearly free.** With a SPARQL endpoint already running, the
  `.htaccess` can content-negotiate `/id/*` straight to a `DESCRIBE <iri>`
  against that endpoint. Roughly ten lines of config makes every minted IRI
  resolvable.
- **Namespace grain: knowledge-graph level, not converter level.** If the
  literature pipeline mints under `csl-to-rdf/` while the occurrence and
  taxonomic pipelines mint under their own namespaces, the same funder gets a
  different IRI per pipeline and the project spends its life reconciling its own
  data against itself. The minting rules below should be shared infrastructure
  that every ingest pipeline calls.
- **Not `.well-known/genid/`.** RDF 1.1 §3.5 reserves that path for skolemized
  blank nodes, and it carries the matching semantics: *this is a stand-in, do
  not attach significance to it*. But a funder in a published graph is a
  first-class queryable entity that we actively want people to link to. Minting
  it under `genid` tells consumers not to rely on precisely the thing we are
  inviting them to rely on.
- **Not fragments on DOI IRIs.** `https://doi.org/10.x/y#author-1` is tempting
  — it inherits the DOI's stability and needs no namespace — but it coins IRIs
  inside a namespace Crossref controls, for which their content negotiation
  defines nothing. Harmless in a private graph; in a published one it is
  asserting definitions in someone else's space, with no way to arbitrate a
  collision if another project mints the same fragment differently.
- **Not `urn:uuid:` (v5) or `tag:`.** Both give deterministic global uniqueness
  with zero infrastructure, and either would be a fine interim for a *private*
  graph. Neither dereferences, which is most of the value in a public graph
  with an agent interface.

### Reuse before minting

**Reuse identifiers that are present in the source data; do not look
identifiers up during the build.** With millions of entities, a reconciliation
step in the ingest path is what stops the graph ever getting built. Minting is
the default; reconciliation is a later, additive pass.

In practice that means: Funder Registry DOIs (Crossref supplies these inline)
and ORCIDs are reused; ROR IDs and Crossref grant DOIs generally are *not*
present in CSL-JSON and therefore are not reused at build time, even though
both exist and both would be better.

Note for the record: the earlier claim in this file that grants never have DOIs
was wrong. Crossref's Grant Linking System has issued grant DOIs since 2019 and
several funders register them. Adopting them requires a lookup keyed on funder
DOI + award string, so it belongs in the deferred reconciliation pass.

### Keying

Two independent decisions, deliberately kept apart because they carry very
different risk.

#### 1. Work-scoped keying — do this everywhere, no risk

Key on the parent work IRI plus the normalized name. This alone eliminates the
cross-source duplicate problem above, with no possibility of conflating two
different entities.

**`position` must not be an input to the key.** Sources disagree about author
order and completeness — one drops a middle author, another orders them
differently — so including position guarantees exactly the duplicates we are
trying to remove. Position is an attribute of the node (see Authorship), not
part of its identity. The same applies to any source or run label.

#### 2. Global (cross-work) merging — tiered by entity type

Merging by name *across* works is what buys "all works by this funder / this
person". The risk is not uniform:

| Entity | Scope | Keying inputs |
|---|---|---|
| Funder (no DOI) | **global** | normalized name |
| Publisher (string) | **global** | normalized name |
| Grant | **global** | funder identifier + normalized award string |
| Person (no ORCID) | work-scoped | work IRI + normalized name |
| Cited work (no DOI) | work-scoped | work IRI + `reference.key`, else hash of `unstructured` |
| CSL encoding `MediaObject` | work-scoped | work IRI (one encoding per work) |

Organization names are long, distinctive, and there are relatively few of them,
so global merging is low-risk. Personal names are short and massively
collision-prone: globally merging `Y. Wang` or `J. Smith` across a biodiversity
literature corpus produces monster nodes with thousands of works spanning a
century — a visible, embarrassing error in a public graph.

**Persons are never merged by name in the IRI.** An earlier draft proposed a
"distinctiveness gate" (merge globally when there is a non-initial given name
and a family name). It blocks `J. Smith` but passes `Wei Wang`, `Li Zhang` and
other full names that are extremely common in recent taxonomic literature, and
an over-merge baked into an IRI is the expensive direction to repair. So person
IRIs assert only what the source says — *someone with this name authored this
work* — and cross-work identity lives in a separate, derived layer: name-based
clusters or candidate matches (weak links, never `owl:sameAs`), plus links from
later reconciliation (ORCID, Wikidata, BHL creators). That layer can be
rebuilt or retracted without re-minting anything.

Known cost: two authors of the same work with identical normalized names
collapse into one node. This is rare and accepted, since adding position to
the key would bring back the cross-source duplicates.

A creator with no usable name (no `literal`, `given` or `family`) is dropped,
as there is nothing to key on.

#### Normalization is load-bearing and must be versioned

Every minted IRI is a hash of the normalizer's output, so changing the
normalizer silently re-mints the entire graph. Pin it, version it, and treat a
change as a migration.

Starting definition, `norm/1`:

- Unicode NFKC, then case-fold to lowercase.
- Strip diacritics. Sources disagree about whether they survive, so stripping
  helps cross-source matching (`Müller` / `Muller`).
- Strip punctuation, including the periods in initials; treat hyphens as
  spaces so `Jean-Pierre` and `Jean Pierre` agree.
- Collapse runs of whitespace, trim.
- Organizations only: drop a leading `the`.

The hashed input string embeds the version and type so the two can never be
confused across a migration:

```
norm/1|person|roderic d m page
norm/1|organization|national science foundation
norm/1|grant|https://doi.org/10.13039/100000001|1452510
```

IRI local part: first 32 hex characters of the SHA-256 of that string.

Work-scoped keys insert the work IRI before the name, e.g.
`norm/1|person|https://doi.org/10.3897/zookeys.976.53859|roderic d m page`.

In code this is `normalize_name()`, `mint_iri()` and `person_iri()` in
`shared.php`. The base is `$config['id_namespace']` in `config.inc.php`, defaulting
to the placeholder `https://w3id.org/kg/id/` until the real namespace is
registered.

### Keeping the gamble reversible

The asymmetry that matters: **merging later is easy, splitting later is hard.**
Under-merging is repaired additively with `schema:sameAs` / `skos:exactMatch`
links, changing no existing IRI. Over-merging is repaired by minting new IRIs
and rewriting every reference — including ones third parties have already
cited from the public endpoint. Choosing name-based merging trades away the
cheap-to-repair direction, which is an acceptable bet only if we can still
reconstruct what was merged. Three cheap obligations, all at build time:

1. **Keep the evidence.** Every minted node carries the raw, un-normalized
   string it was minted from, and the source it came from. A later split is only
   computable if we can still see what went in.
2. **Record the normalizer version** on the graph, per the above.
3. **Audit by query, not by curation.** Over-merges are detectable after the
   fact without reconciling anything: nodes with implausible work counts,
   publication spans beyond ~60 years, or authorship across unrelated subject
   areas. That is a SPARQL query run against the finished graph — which fits a
   build-first workflow, rather than blocking it.

Provenance also argues for a **named graph per source DOI**, which gives
"where did this triple come from" for free and allows re-ingesting a single work
without a global rebuild. The emitter is already called `csl_to_quads`, so the
slot exists.

### Reversals

Recorded so the reasoning is not re-litigated:

- **Blank nodes, even deterministically labelled, are out.** Earlier drafts kept
  them for entities where cross-work identity was implausible. Publication kills
  that: blank nodes are not addressable in SPARQL results or federated queries.
  Work-scoped *IRIs* give the same "no cross-work claim" semantics while
  remaining queryable.
- **Global name-merging of persons is out.** It was briefly adopted with a
  distinctiveness gate; reverted because full-name collisions are too common in
  the corpus (see Keying).
- **`.well-known/genid/` and DOI fragments are out**, for the reasons under
  "Namespace". Both were earlier recommendations; both are fine privately and
  wrong publicly.

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

### Current decision

Ordering is postponed. We emit unordered `schema:creator`, partly because
ORCID's own JSON-LD ignores author order too. When ordering is needed, the
leaning is `schema:position` (option 1), with the `Role` pattern (option 4)
kept for the day we start ingesting affiliations or corresponding-author
flags.

### Identity, again

Superseded by the keying rules in the identity section above. Two points bear
repeating here because they bite specifically on authors:

- **`position` is not part of the key.** An earlier draft keyed authors on
  `(work, position, name)`. That is actively wrong: sources describing the same
  DOI disagree about author order and completeness, so including position
  re-creates the duplicate-author-per-source problem it was meant to avoid.
  Position is emitted as `schema:position` on the node and plays no part in its
  identity.
- **Person IRIs are always work-scoped.** "All works by this person" queries
  go through the derived clustering/reconciliation layer described under
  Keying, not through name-merged IRIs.

## Open questions

Append new sections here as they come up. Suggested format: **Problem**,
**Options**, **Current leaning**.
