import type { CheckResult } from "../types";
import { ConflictError } from "../types";

/**
 * Accès aux fichiers d'un site, quel que soit l'endroit où ils vivent
 * (dépôt GitHub / GitLab / Bitbucket, serveur SFTP ou FTP, dossier local de démonstration).
 * Tous les chemins sont relatifs à la racine configurée et déjà vérifiés par safeJoin.
 */
export interface FileBackend {
  readonly label: string;
  test(): Promise<CheckResult[]>;
  read(path: string): Promise<{ content: Buffer; version: string } | null>;
  /** Liste récursive des fichiers sous `dir` (chemins relatifs à la racine). */
  list(dir: string, maxDepth?: number): Promise<string[]>;
  /**
   * Écrit toutes les modifications d'un coup (un seul commit pour les dépôts).
   * `expected` : version lue de chaque fichier (null = le fichier ne doit pas exister).
   * Si un fichier a changé entre-temps : RetryableConflict.
   */
  commit(
    writes: { path: string; content: Buffer | null }[],
    message: string,
    expected: Record<string, string | null>,
  ): Promise<{ ref: string }>;
  close?(): Promise<void>;
}

/** Le site a bougé pendant l'écriture : le moteur relit et recommence. */
export class RetryableConflict extends ConflictError {
  constructor(detail?: string) {
    super(detail);
    this.name = "RetryableConflict";
  }
}
