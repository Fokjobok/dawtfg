import { useEffect, useState } from 'react'
import Button from './Button.jsx'
import FormField from './FormField.jsx'
import { getMedals } from '../services/medals.js'
import styles from './MedalPanel.module.scss'

export default function MedalPicker({
  specialties,
  value,
  onChange,
  ownedIds,
  disabled,
  refreshKey,
}) {
  const [specialtyId, setSpecialtyId] = useState('')
  const [page, setPage] = useState(1)
  const [retry, setRetry] = useState(0)
  const [result, setResult] = useState(null)

  // Identifica exactamente a qué consulta pertenece cada respuesta.
  const queryKey = `${specialtyId}:${page}:${refreshKey}:${retry}`

  useEffect(() => {
    if (!specialtyId) return

    let active = true

    getMedals(specialtyId, page)
      .then((response) => {
        if (active) {
          setResult({ key: queryKey, response })
        }
      })
      .catch(() => {
        if (active) {
          setResult({ key: queryKey, error: true })
        }
      })

    return () => {
      active = false
    }
  }, [specialtyId, page, queryKey])

  const status = !specialtyId
    ? 'idle'
    : result?.key !== queryKey
      ? 'loading'
      : result.error
        ? 'error'
        : 'ready'

  const medals = status === 'ready' ? result.response.data : []
  const pagination = status === 'ready' ? result.response.meta : null

  function changePage(nextPage) {
    onChange('')
    setPage(nextPage)
  }

  return (
    <>
      <FormField
        as="select"
        id="assigned-medal-specialty"
        label="Categoría de la medalla"
        value={specialtyId}
        disabled={disabled}
        onChange={(event) => {
          onChange('')
          setSpecialtyId(event.target.value)
          setPage(1)
        }}
      >
        <option value="">Selecciona un juego o materia</option>
        {specialties.map((specialty) => (
          <option key={specialty.id} value={specialty.id}>
            {specialty.name}
          </option>
        ))}
      </FormField>

      <FormField
        as="select"
        id="assigned-medal"
        label="Medalla"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        disabled={disabled || status !== 'ready' || medals.length === 0}
        required
      >
        <option value="">Selecciona una medalla</option>
        {medals.map((medal) => (
          <option key={medal.id} value={medal.id}>
            {medal.name}
            {ownedIds.includes(medal.id) ? ' — asignada' : ''}
          </option>
        ))}
      </FormField>

      {status === 'loading' && (
        <p role="status">Cargando medallas de la categoría…</p>
      )}

      {status === 'ready' && medals.length === 0 && (
        <p>No hay medallas en esta categoría.</p>
      )}

      {status === 'error' && (
        <div>
          <p role="alert">No se pudieron cargar las medallas.</p>
          <Button
            disabled={disabled}
            onClick={() => {
              onChange('')
              setRetry((current) => current + 1)
            }}
          >
            Reintentar
          </Button>
        </div>
      )}

      {pagination && pagination.last_page > 1 && (
        <div className={styles.actions}>
          <Button
            variant="secondary"
            disabled={disabled || page <= 1}
            onClick={() => changePage(page - 1)}
          >
            Anterior
          </Button>

          <span>
            Página {page} de {pagination.last_page}
          </span>

          <Button
            variant="secondary"
            disabled={disabled || page >= pagination.last_page}
            onClick={() => changePage(page + 1)}
          >
            Siguiente
          </Button>
        </div>
      )}
    </>
  )
}
