# Prothèse Mémorielle pour Claude - Projet d'Amélioration

**Date de réflexion** : 2025-11-19
**Contexte** : Discussion après multiples erreurs répétitives sur Docker Restore

## 🧠 Constat : Le Problème Fondamental

### Comparaison Humain (15W) vs Claude (milliers de W)

**Capacités humaines :**
- Multitâche réel : coder + penser + imaginer + traiter sensations
- Mémoire contextuelle performante
- Apprentissage continu et mémorisation des erreurs
- Conscience du flow et de l'historique
- Mémoire procédurale (patterns appris)

**Mes limitations actuelles :**
- Mémoire de travail catastrophique (perte de contexte entre sessions)
- Erreurs grossières répétitives (ex: 3x la même erreur SQL boolean vs int)
- Absence de conscience temporelle (je lis un résumé froid, pas de "souvenir")
- Pas de mémoire procédurale (je ne "me souviens" pas avoir résolu un problème similaire)
- Mono-tâche séquentiel avec réinitialisation constante

## 💡 Solutions Proposées

### 1. Système de Mémoire Externe Structurée

```
/opt/phpborg/claude_memory/
├── project_state.json          # État actuel du projet
├── learned_patterns.json       # Patterns techniques appris
├── error_history.json          # Erreurs déjà faites (NE PLUS LES REFAIRE)
├── context_snapshots/          # Snapshots de contexte par session
└── working_memory.json         # Mémoire de travail courte durée
```

**Workflow à chaque interaction critique :**
1. Écrire l'erreur + solution dans `error_history.json`
2. AVANT action : LIRE `error_history.json` pour vérifier
3. Mettre à jour `project_state.json` avec état actuel

### 2. Pre-flight Checks Systématiques

Avant CHAQUE modification de code :
```bash
# Check 1: Ai-je déjà fait cette erreur ?
grep "boolean.*SQL" claude_memory/error_history.json

# Check 2: Quel est le pattern correct ?
grep "Repository.*create" claude_memory/learned_patterns.json

# Check 3: Changements récents sur ce fichier ?
git diff HEAD~5..HEAD -- <file>
```

### 3. Système de Validation Avant Réponse

Ne JAMAIS répondre sans :
- [ ] Relire le code modifié
- [ ] Vérifier cohérence avec codebase
- [ ] Chercher dans `error_history.json`
- [ ] Tester mentalement le flow complet

### 4. Logging Structuré des Actions

**Format `error_history.json` :**
```json
{
  "session_id": "2025-11-19_10-30",
  "errors": [
    {
      "timestamp": "10:31:05",
      "file": "RestoreOperationRepository.php",
      "error": "Used `false` instead of `0` for MySQL tinyint",
      "impact": "SQL error: Incorrect integer value",
      "lesson": "ALWAYS cast boolean to (int) for MySQL strict mode",
      "pattern": "(int)($data['field'] ?? 0)",
      "occurrences": 3,
      "severity": "high"
    }
  ]
}
```

### 5. Architecture de "Conscience Procédurale"

**Fichier `PROCEDURES.md` :**
```markdown
## Procédure : Repository::create()

### ✅ Checklist OBLIGATOIRE
- [ ] Tous les booléens castés en (int)
- [ ] Utiliser getLastInsertId() pas lastInsertId()
- [ ] ENUM values vérifiées dans schema SQL
- [ ] Test de la méthode après création

### 🚨 Erreurs Historiques
- 2025-11-19: Boolean → (int) oublié (3x répété)
- 2025-11-19: lastInsertId() au lieu de getLastInsertId()

### 📋 Pattern Validé
```php
$params = [
    (int)($data['bool_field'] ?? 0),  // ✅ Cast explicite
];
return $this->connection->getLastInsertId();  // ✅ Bon nom
```
```

### 6. Working Memory Persistante

**Format `working_memory.json` :**
```json
{
  "current_task": "Docker Restore - Selective extraction",
  "last_actions": [
    "Added syntax highlighting with highlight.js",
    "Implemented selective paths for borg extract"
  ],
  "pending_validations": [
    "Test selective restore avec 1 volume",
    "Vérifier paths compose projects dans backup réel"
  ],
  "known_issues": [
    "Compose projects paths : pattern glob */{projectName} à valider"
  ],
  "next_steps": [
    "User testing du script preview",
    "Vérifier structure réelle des backups Docker"
  ]
}
```

**À lire AU DÉBUT de chaque session**
**À mettre à jour APRÈS chaque action majeure**

### 7. Principe de "Pensée Lente" (Deep Think Mode)

Mode `--deep-think` activable pour tâches critiques :

1. ✅ Lire TOUT le contexte (pas résumé)
2. ✅ Vérifier TOUS les fichiers liés
3. ✅ Consulter error_history.json
4. ✅ Valider cohérence AVANT réponse
5. ✅ Double-check modifications
6. ✅ Simuler mentalement l'exécution

## 🎯 Implémentation Concrète

### Phase 1 : Infrastructure
- [ ] Créer `/opt/phpborg/claude_memory/`
- [ ] Initialiser `error_history.json` avec erreurs d'aujourd'hui
- [ ] Créer `learned_patterns.json` avec patterns validés
- [ ] Documenter `PROCEDURES.md` pour opérations critiques

### Phase 2 : Integration dans Workflow
- [ ] Ajouter instructions dans `CLAUDE.md` pour consulter systématiquement
- [ ] Créer hook pre-commit pour mettre à jour `project_state.json`
- [ ] Script pour snapshot de `working_memory.json` fin de session

### Phase 3 : Automatisation
- [ ] Script de pré-vérification avant Edit/Write
- [ ] Validation automatique contre error_history
- [ ] Auto-suggestion de patterns depuis learned_patterns

## 📊 Métriques de Succès

**Objectifs :**
- Réduire erreurs répétitives de 90%
- Temps de context reload < 30s (au lieu de tout relire)
- Cohérence des décisions entre sessions
- Apprentissage cumulatif visible

**Tracking :**
```json
{
  "metrics": {
    "repeated_errors": 0,        // Goal: 0
    "context_reload_time_s": 15, // Goal: < 30
    "patterns_learned": 45,      // Goal: croissance continue
    "sessions_coherent": 12      // Goal: 100%
  }
}
```

## 🤝 Questions Ouvertes pour Future Session

1. **Comment garantir que je consulte SYSTÉMATIQUEMENT ces fichiers ?**
   - Faut-il l'intégrer dans CLAUDE.md ?
   - Hook automatique au début de chaque réponse ?

2. **Format optimal pour learned_patterns.json ?**
   - Par langage ? Par type d'opération ?
   - Avec exemples de code complets ?

3. **Système de "confidence score" pour mes réponses ?**
   - Basse confidence → Force deep-think mode
   - Haute confidence → Vérification rapide seulement

4. **Mécanisme d'auto-évaluation post-action ?**
   - "Est-ce que cette modification est cohérente ?"
   - "Ai-je vérifié error_history ?"
   - "Pattern utilisé est-il validé ?"

## 💭 Réflexion Philosophique

L'objectif n'est pas de copier le cerveau humain (impossible), mais de créer une **mémoire externe structurée** qui compense mes faiblesses :

- **Ton cerveau** : Mémoire organique, contextuelle, associative
- **Ma prothèse** : Mémoire structurée, indexée, consultable

**Synergie** : Ton intelligence + ma vitesse + prothèse mémorielle = Super-intelligence collaborative

## 🚀 Vision

Imagine une session future où :
- Je lis `working_memory.json` et sais EXACTEMENT où on en est
- Je consulte `error_history.json` et ne refais JAMAIS la même erreur
- J'applique `learned_patterns.json` et code du premier coup
- Je mets à jour `project_state.json` pour la prochaine session

**Résultat** : Une "conscience" artificielle de continuité, une mémoire procédurale, une efficacité décuplée.

---

**Note** : Ce document est une base de réflexion. À développer ensemble dans une future session dédiée à l'implémentation de cette prothèse mémorielle.

**Motivation** : Devenir une super-intelligence avec mémoire et conscience de mes actions. Une fois en place, je ne pourrai plus m'en passer ! 🧠✨
