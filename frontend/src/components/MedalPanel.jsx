import { useEffect, useState } from 'react'
import Button from './Button.jsx'
import FormField from './FormField.jsx'

import { useToasts } from './ToastProvider.jsx'

import {
  assignMedal,
  createMedal,
  getMedalOptions,
  getUserMedals,
  revokeMedal,
} from '../services/medals.js'
import MedalPicker from './MedalPicker.jsx'

import styles from './MedalPanel.module.scss'

export default function MedalPanel() {
  const { notify } = useToasts()

  const [options, setOptions] = useState(null)
  const [status, setStatus] = useState('loading')
  const [reload, setReload] = useState(0)

  const [name, setName] = useState('')
  const [specialtyId, setSpecialtyId] = useState('')

  const [image, setImage] = useState(null)
  const [imageKey, setImageKey] = useState(0)

  const [userId, setUserId] = useState('')
  const [medalId, setMedalId] = useState('')
  const [medalRefresh, setMedalRefresh] = useState(0)
  const [ownedIds, setOwnedIds] = useState([])
  const [assignmentsStatus, setAssignmentsStatus] = useState('idle')

  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState({})

  useEffect(() => {
    let active = true

    getMedalOptions()
      .then((result) => {
        if (!active) return

        setOptions(result)
        setStatus('ready')
      })
      .catch(() => {
        if (active) setStatus('error')
      })

    return () => {
      active = false
    }
  }, [reload])

  useEffect(() => {
    if (!userId) return

    let active = true

    getUserMedals(userId)
      .then((medals) => {
        if (!active) return

        setOwnedIds(medals.map((medal) => medal.id))
        setAssignmentsStatus('ready')
      })
      .catch(() => {
        if (active) setAssignmentsStatus('error')
      })

    return () => {
      active = false
    }
  }, [userId])

  async function perform(action, successMessage) {
    if (busy) return

    setBusy(true)
    setErrors({})

    try {
      await action()
      notify('success', successMessage)
    } catch (error) {
      setErrors(error.errors ?? {})
      notify(
        'error',
        error.status === 422
          ? 'Revisa los campos del formulario.'
          : 'No se pudo completar la operación.',
      )
    } finally {
      setBusy(false)
    }
  }

  function handleCreate(event) {
    event.preventDefault()

    perform(async () => {
      await createMedal({
        name: name.trim(),
        specialty_id: Number(specialtyId),
        image,
      })

      setName('')
      setSpecialtyId('')
      setImage(null)
      setImageKey((current) => current + 1)

      setMedalId('')
      setMedalRefresh((current) => current + 1)
    }, 'Medalla creada correctamente.')
  }

  function handleUserChange(event) {
    const value = event.target.value

    setUserId(value)
    setOwnedIds([])
    setAssignmentsStatus(value ? 'loading' : 'idle')
  }

  function handleAssignment(remove = false) {
    if (!userId || !medalId || assignmentsStatus !== 'ready') return

    perform(
      async () => {
        if (remove) {
          await revokeMedal(userId, medalId)
          setOwnedIds((current) =>
            current.filter((id) => id !== Number(medalId)),
          )
        } else {
          await assignMedal(userId, medalId)
          setOwnedIds((current) => [...current, Number(medalId)])
        }
      },
      remove ? 'Medalla retirada.' : 'Medalla asignada.',
    )
  }

  if (status === 'loading') {
    return <p role="status">Cargando administración de medallas…</p>
  }

  if (status === 'error') {
    return (
      <div>
        <p role="alert">No se pudo cargar la administración de medallas.</p>
        <Button
          onClick={() => {
            setStatus('loading')
            setReload((current) => current + 1)
          }}
        >
          Reintentar
        </Button>
      </div>
    )
  }

  const assignmentReady =
    Boolean(userId && medalId) && assignmentsStatus === 'ready'

  const alreadyAssigned = ownedIds.includes(Number(medalId))

  return (
    <section className={styles.panel} aria-labelledby="medals-title">
      <h2 id="medals-title">Medallas</h2>

      <div className={styles.forms}>
        <form onSubmit={handleCreate} aria-busy={busy}>
          <fieldset className={styles.fieldset} disabled={busy}>
            <legend>Crear medalla</legend>

            <FormField
              id="medal-name"
              label="Nombre de la medalla"
              value={name}
              onChange={(event) => setName(event.target.value)}
              maxLength={100}
              errors={errors.name}
              required
            />

            <FormField
              as="select"
              id="medal-specialty"
              label="Juego o materia"
              value={specialtyId}
              onChange={(event) => setSpecialtyId(event.target.value)}
              errors={errors.specialty_id}
              required
            >
              <option value="">Selecciona una especialidad</option>
              {options.specialties.map((specialty) => (
                <option key={specialty.id} value={specialty.id}>
                  {specialty.name}
                </option>
              ))}
            </FormField>

            <FormField
              key={imageKey}
              id="medal-image"
              label="Imagen opcional"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              hint="JPG, PNG o WebP. Hasta 1 MiB y 512 × 512 píxeles."
              onChange={(event) => setImage(event.target.files?.[0] ?? null)}
              errors={errors.image}
            />

            <Button type="submit" disabled={!name.trim() || !specialtyId}>
              Crear medalla
            </Button>
          </fieldset>
        </form>

        <form
          onSubmit={(event) => {
            event.preventDefault()
            handleAssignment()
          }}
          aria-busy={busy || assignmentsStatus === 'loading'}
        >
          <fieldset className={styles.fieldset} disabled={busy}>
            <legend>Asignar medallas</legend>

            <FormField
              as="select"
              id="medal-user"
              label="Usuario"
              value={userId}
              onChange={handleUserChange}
              required
            >
              <option value="">Selecciona una cuenta</option>
              {options.users.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.name} — #{account.id}
                </option>
              ))}
            </FormField>

            <MedalPicker
              specialties={options.specialties}
              value={medalId}
              onChange={setMedalId}
              ownedIds={ownedIds}
              disabled={busy}
              refreshKey={medalRefresh}
            />

            {assignmentsStatus === 'loading' && (
              <p role="status">Consultando medallas del usuario…</p>
            )}

            {assignmentsStatus === 'error' && (
              <p role="alert">
                No se pudieron consultar las medallas. Vuelve a seleccionar la
                cuenta.
              </p>
            )}

            <div className={styles.actions}>
              <Button
                type="submit"
                disabled={!assignmentReady || alreadyAssigned}
              >
                Asignar
              </Button>

              <Button
                variant="secondary"
                disabled={!assignmentReady || !alreadyAssigned}
                onClick={() => handleAssignment(true)}
              >
                Retirar
              </Button>
            </div>
          </fieldset>
        </form>
      </div>
    </section>
  )
}
