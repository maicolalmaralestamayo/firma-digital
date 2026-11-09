import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { FileDrop } from '../components/FileDrop';
import { ApiError, documentsApi } from '../lib/api';
import { formatDate, statusLabel } from '../lib/format';
import type { DocumentResource, Pagination } from '../lib/types';

type RoleFilter = 'all' | 'owner' | 'signer';
type StatusFilter = '' | 'open' | 'sealed';

function CreateDocumentForm({ onCreated }: { onCreated: () => void }) {
  const navigate = useNavigate();
  const [title, setTitle] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [count, setCount] = useState(2);
  const [emails, setEmails] = useState<string[]>(['', '']);
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setEmails((current) => {
      const next = [...current];
      while (next.length < count) {
        next.push('');
      }
      return next.slice(0, count);
    });
  }, [count]);

  const updateEmail = (index: number, value: string) => {
    setEmails((current) => current.map((email, position) => (position === index ? value : email)));
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (!file) {
      setError('Sube el PDF que se firmará.');
      return;
    }

    const signers = emails.map((email) => email.trim()).filter(Boolean);

    if (signers.length !== count) {
      setError(`Completa los ${count} correos de firmantes.`);
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      const form = new FormData();
      form.append('file', file);
      form.append('title', title);
      form.append('signers_required', String(count));
      signers.forEach((email) => form.append('signers[]', email));

      if (reason.trim()) {
        form.append('reason', reason.trim());
      }

      const response = await documentsApi.create(form);
      navigate(`/app/documents/${response.data.id}`);
      onCreated();
    } catch (requestError) {
      setError(
        requestError instanceof ApiError
          ? requestError.errors.map((item) => item.detail).join(' ')
          : 'No se pudo crear el documento.',
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form className="card create-form" onSubmit={submit}>
      <div className="card__head">
        <div>
          <h2>Nuevo documento para firmar</h2>
          <p>
            Define cuántas firmas se necesitan e invita por correo a cada firmante. El documento se cierra
            automáticamente cuando firma la última persona.
          </p>
        </div>
      </div>

      <div className="two-columns">
        <div className="field">
          <label className="field__label" htmlFor="title">
            Título <span className="required">*</span>
          </label>
          <input
            id="title"
            className="input"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            maxLength={180}
            placeholder="Contrato de servicios"
            required
          />
        </div>

        <div className="field">
          <label className="field__label" htmlFor="signers-required">
            Cantidad de firmantes <span className="required">*</span>
          </label>
          <input
            id="signers-required"
            className="input"
            type="number"
            min={1}
            max={20}
            value={count}
            onChange={(event) => setCount(Math.max(1, Math.min(20, Number(event.target.value) || 1)))}
            required
          />
        </div>
      </div>

      <FileDrop
        label="Documento PDF"
        hint="PDF sin contraseña, hasta 20 MB"
        accept="application/pdf,.pdf"
        file={file}
        onFile={setFile}
        required
      />

      <div className="field">
        <span className="field__label">
          Firmantes <span className="required">*</span>
        </span>
        <div className="signers-grid">
          {emails.map((email, index) => (
            <div className="field field--compact" key={index}>
              <label className="field__label" htmlFor={`signer-${index}`}>
                Firmante {index + 1}
              </label>
              <input
                id={`signer-${index}`}
                className="input"
                type="email"
                value={email}
                onChange={(event) => updateEmail(index, event.target.value)}
                placeholder="correo@ejemplo.com"
                required
              />
            </div>
          ))}
        </div>
        <span className="hint">
          Si un invitado todavía no tiene cuenta, la invitación quedará asociada a su correo y se activará
          cuando se registre.
        </span>
      </div>

      <div className="field">
        <label className="field__label" htmlFor="create-reason">
          Motivo <span className="optional">(opcional)</span>
        </label>
        <input
          id="create-reason"
          className="input"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          maxLength={190}
          placeholder="Contrato de prestación de servicios 2026"
        />
      </div>

      {error && <div className="alert alert--danger">{error}</div>}

      <button type="submit" className="btn btn--primary" disabled={submitting}>
        {submitting ? 'Creando documento…' : 'Crear y compartir documento'}
      </button>
    </form>
  );
}

export default function Dashboard() {
  const [documents, setDocuments] = useState<DocumentResource[]>([]);
  const [pagination, setPagination] = useState<Pagination | null>(null);
  const [role, setRole] = useState<RoleFilter>('all');
  const [status, setStatus] = useState<StatusFilter>('');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search), 320);
    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    documentsApi
      .list({ role, status, search: debouncedSearch })
      .then((response) => {
        if (cancelled) {
          return;
        }

        setDocuments(response.data);
        setPagination(response.meta.pagination);
      })
      .catch((requestError: unknown) => {
        if (!cancelled) {
          setError(
            requestError instanceof ApiError
              ? requestError.message
              : 'No se pudieron cargar los documentos.',
          );
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [role, status, debouncedSearch, reloadKey]);

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <span className="eyebrow">Panel</span>
          <h1>Mis documentos</h1>
          <p>
            Documentos que creaste o en los que te invitaron a firmar. {pagination ? `${pagination.total} en total.` : ''}
          </p>
        </div>
        <div className="button-row">
          <button
            type="button"
            className="btn btn--secondary"
            onClick={() => setShowCreate((value) => !value)}
          >
            {showCreate ? 'Cerrar formulario' : '+ Nuevo documento'}
          </button>
        </div>
      </header>

      {showCreate && (
        <CreateDocumentForm
          onCreated={() => {
            setShowCreate(false);
            setReloadKey((value) => value + 1);
          }}
        />
      )}

      <div className="toolbar">
        <input
          className="input toolbar__search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Buscar por título…"
          aria-label="Buscar documentos"
        />

        <select
          className="select"
          value={role}
          onChange={(event) => setRole(event.target.value as RoleFilter)}
          aria-label="Filtrar por rol"
        >
          <option value="all">Todos mis documentos</option>
          <option value="owner">Creados por mí</option>
          <option value="signer">Compartidos conmigo</option>
        </select>

        <select
          className="select"
          value={status}
          onChange={(event) => setStatus(event.target.value as StatusFilter)}
          aria-label="Filtrar por estado"
        >
          <option value="">Todos los estados</option>
          <option value="open">Abiertos</option>
          <option value="sealed">Cerrados</option>
        </select>

        <button
          type="button"
          className="btn btn--ghost btn--sm"
          onClick={() => setReloadKey((value) => value + 1)}
          disabled={loading}
        >
          Actualizar
        </button>
      </div>

      {error && <div className="alert alert--danger">{error}</div>}

      {loading ? (
        <div className="page-loading">
          <span className="spinner" aria-hidden="true" /> Cargando documentos…
        </div>
      ) : documents.length === 0 ? (
        <div className="empty-state">
          <span aria-hidden="true">🗂</span>
          <h2>No hay documentos</h2>
          <p>Crea un documento, invita a los firmantes y comienza el circuito de firmas.</p>
          <button type="button" className="btn btn--primary" onClick={() => setShowCreate(true)}>
            Crear el primero
          </button>
        </div>
      ) : (
        <div className="document-grid">
          {documents.map((document) => {
            const attributes = document.attributes;

            return (
              <Link
                className="card document-card"
                key={document.id}
                to={`/app/documents/${document.id}`}
              >
                <div className="document-card__head">
                  <h2>{attributes.title}</h2>
                  <span className={`badge ${attributes.sealed ? 'badge--sealed' : 'badge--open'}`}>
                    {statusLabel(attributes.status)}
                  </span>
                </div>

                <p className="muted document-card__meta">
                  {formatDate(attributes.created_at)} · {attributes.page_count} página(s) ·{' '}
                  {attributes.my_role === 'owner'
                    ? 'Creador'
                    : attributes.my_role === 'owner_and_signer'
                      ? 'Creador y firmante'
                      : 'Firmante'}
                </p>

                <div className="progress" aria-hidden="true">
                  <span
                    style={{
                      width: `${Math.round(
                        (attributes.signature_count / Math.max(1, attributes.signers_required)) * 100,
                      )}%`,
                    }}
                  />
                </div>

                <div className="document-card__footer">
                  <span>
                    {attributes.signature_count} de {attributes.signers_required} firmas
                  </span>
                  <span className="mono">{`${attributes.current_sha256.slice(0, 10)}…`}</span>
                </div>

                {attributes.permissions.can_sign && (
                  <span className="badge badge--action">Te toca firmar</span>
                )}
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}
