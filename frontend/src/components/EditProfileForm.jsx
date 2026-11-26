import { useEffect, useRef, useState } from 'react'
import { useToasts } from './ToastProvider.jsx'
import Button from './Button.jsx'
import FormField from './FormField.jsx'

import { updateOwnProfile } from '../services/profiles.js'

import styles from './EditProfileForm.module.scss'

import { EnrolledCoursePicker } from './ProfileCourses.jsx'

export default function EditProfileForm({ profile, onSaved }) {
  const { notify } = useToasts()
  const formRef = useRef(null)

  const [name, setName] = useState(profile.name)

  const [bio, setBio] = useState(profile.bio ?? '')
  const [isPrivate, setIsPrivate] = useState(profile.is_private)
  const [bioIsPrivate, setBioIsPrivate] = useState(
    profile.bio_is_private ?? false,
  )

  const [medalsIsPrivate, setMedalsIsPrivate] = useState(
    profile.medals_is_private ?? false,
  )

  const [coursesIsPrivate, setCoursesIsPrivate] = useState(
    profile.enrollments_is_private ?? false,
  )

  const [featuredIds, setFeaturedIds] = useState(
    profile.featured_course_ids ?? [],
  )

  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState({})

  const [avatar, setAvatar] = useState(null)
  const [removeAvatar, setRemoveAvatar] = useState(false)
  const [avatarInputKey, setAvatarInputKey] = useState(0)

  const [secondsLeft, setSecondsLeft] = useState(
    profile.save_limits?.retry_after ?? 0,
  )

  const [cooldownUntil, setCooldownUntil] = useState(() => {
    const seconds = profile.save_limits?.retry_after ?? 0

    return seconds > 0 ? Date.now() + seconds * 1000 : 0
  })

  const hasChanges =
    name.trim() !== profile.name.trim() ||
    bio.trim() !== (profile.bio ?? '').trim() ||
    isPrivate !== profile.is_private ||
    bioIsPrivate !== Boolean(profile.bio_is_private) ||
    medalsIsPrivate !== Boolean(profile.medals_is_private) ||
    coursesIsPrivate !== Boolean(profile.enrollments_is_private) ||
    JSON.stringify(featuredIds) !==
      JSON.stringify(profile.featured_course_ids ?? []) ||
    avatar !== null ||
    (removeAvatar && Boolean(profile.avatar_url))

  useEffect(() => {
    if (!cooldownUntil) return

    const timer = window.setInterval(() => {
      const remaining = Math.max(
        0,
        Math.ceil((cooldownUntil - Date.now()) / 1000),
      )

      setSecondsLeft(remaining)

      if (remaining === 0) {
        window.clearInterval(timer)
      }
    }, 250)

    return () => window.clearInterval(timer)
  }, [cooldownUntil])

  useEffect(() => {
    if (saving || Object.keys(errors).length === 0) return

    formRef.current?.querySelector('[aria-invalid="true"]')?.focus()
  }, [errors, saving])

  function startCooldown(seconds) {
    const remaining = Math.min(60, Math.max(0, Number(seconds) || 0))

    setSecondsLeft(remaining)
    setCooldownUntil(remaining > 0 ? Date.now() + remaining * 1000 : 0)
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (saving || !hasChanges || secondsLeft > 0) return

    setSaving(true)
    setErrors({})

    try {
      const { data: updated, meta } = await updateOwnProfile({
        name,
        bio: bio || null,
        is_private: isPrivate,
        bio_is_private: bioIsPrivate,
        medals_is_private: medalsIsPrivate,
        enrollments_is_private: coursesIsPrivate,
        featured_course_ids: featuredIds,
        ...(avatar ? { avatar } : {}),
        ...(removeAvatar ? { remove_avatar: true } : {}),
      })

      startCooldown(updated.save_limits.retry_after)

      setName(updated.name)
      setBio(updated.bio ?? '')
      setIsPrivate(updated.is_private)
      setBioIsPrivate(updated.bio_is_private ?? false)
      setMedalsIsPrivate(updated.medals_is_private ?? false)

      setCoursesIsPrivate(updated.enrollments_is_private ?? false)
      setFeaturedIds(updated.featured_course_ids ?? [])

      setAvatar(null)
      setRemoveAvatar(false)
      setAvatarInputKey((current) => current + 1)

      onSaved(updated)

      notify(
        meta.updated ? 'success' : 'info',
        meta.updated
          ? 'Perfil actualizado correctamente.'
          : 'Los datos ya estaban actualizados.',
      )
    } catch (error) {
      setErrors(error.errors ?? {})

      if (error.status === 429) {
        startCooldown(error.retryAfter)
      }

      notify(
        [422, 429].includes(error.status) ? 'warning' : 'error',
        [401, 419].includes(error.status)
          ? 'La sesión ha caducado. Vuelve a iniciar sesión.'
          : error.status === 429
            ? 'Podrás guardar de nuevo al terminar la cuenta atrás.'
            : error.status === 422
              ? 'Revisa los datos del formulario.'
              : 'No se pudo guardar el perfil. Inténtalo de nuevo.',
      )
    } finally {
      setSaving(false)
    }
  }

  const featuredErrors = Object.entries(errors)
    .filter(
      ([field]) =>
        field === 'featured_course_ids' ||
        field.startsWith('featured_course_ids.'),
    )
    .flatMap((entry) => entry[1])

  return (
    <form
      ref={formRef}
      className={styles.form}
      onSubmit={handleSubmit}
      aria-busy={saving}
      aria-labelledby="profile-edit-title"
    >
      <h2 id="profile-edit-title" tabIndex={-1}>
        Editar mi perfil
      </h2>

      <p className={styles.intro}>
        Actualiza cómo te presentas y decide qué pueden ver otras personas.
      </p>

      <fieldset className={styles.fields} disabled={saving}>
        <legend>Información del perfil</legend>

        <FormField
          id="profile-name"
          name="name"
          label="Nombre"
          autoComplete="name"
          required
          maxLength={100}
          value={name}
          onChange={(event) => setName(event.target.value)}
          errors={errors.name}
        />

        <div className={styles.avatarSettings}>
          <FormField
            key={avatarInputKey}
            id="profile-avatar"
            name="avatar"
            type="file"
            label="Cambiar avatar"
            accept="image/jpeg,image/png,image/webp"
            hint="JPG, PNG o WebP. Máximo 2 MiB y 1024 × 1024 píxeles. Se aplicará al guardar."
            onChange={(event) => {
              setAvatar(event.target.files?.[0] ?? null)
              setRemoveAvatar(false)
            }}
            errors={errors.avatar}
          />

          {profile.avatar_url && (
            <label className={styles.checkbox}>
              <input
                type="checkbox"
                name="remove_avatar"
                checked={removeAvatar}
                aria-invalid={Boolean(errors.remove_avatar?.length)}
                aria-describedby={
                  errors.remove_avatar?.length
                    ? 'profile-remove-avatar-error'
                    : undefined
                }
                onChange={(event) => {
                  setRemoveAvatar(event.target.checked)
                  setAvatar(null)
                  setAvatarInputKey((current) => current + 1)
                }}
              />
              <span>Eliminar mi avatar actual al guardar</span>
            </label>
          )}

          {errors.remove_avatar?.length > 0 && (
            <p id="profile-remove-avatar-error" className={styles.error}>
              {errors.remove_avatar.join(' ')}
            </p>
          )}
        </div>

        <FormField
          as="textarea"
          id="profile-bio"
          name="bio"
          label="Biografía"
          rows={5}
          maxLength={2000}
          value={bio}
          onChange={(event) => setBio(event.target.value)}
          errors={errors.bio}
        />

        <fieldset className={styles.privacy}>
          <legend>Privacidad</legend>
          <FormField
            as="select"
            id="profile-visibility"
            name="is_private"
            label="Visibilidad del perfil"
            hint="El nombre, el avatar y el nivel siempre son públicos."
            value={isPrivate ? 'private' : 'public'}
            onChange={(event) => setIsPrivate(event.target.value === 'private')}
            errors={errors.is_private}
          >
            <option value="public">Público</option>
            <option value="private">Privado</option>
          </FormField>

          <FormField
            as="select"
            id="profile-bio-visibility"
            name="bio_is_private"
            label="Visibilidad de la biografía"
            hint={
              isPrivate
                ? 'El perfil privado oculta esta sección. Se conserva tu preferencia.'
                : 'Elige si otras personas pueden leer tu biografía.'
            }
            value={bioIsPrivate ? 'private' : 'public'}
            onChange={(event) =>
              setBioIsPrivate(event.target.value === 'private')
            }
            disabled={isPrivate}
            errors={errors.bio_is_private}
          >
            <option value="public">Pública</option>
            <option value="private">Solo yo</option>
          </FormField>

          <FormField
            as="select"
            id="profile-medals-visibility"
            name="medals_is_private"
            label="Visibilidad de las medallas"
            hint="El perfil privado también oculta las medallas en las tarjetas de cursos."
            value={medalsIsPrivate ? 'private' : 'public'}
            onChange={(event) =>
              setMedalsIsPrivate(event.target.value === 'private')
            }
            disabled={isPrivate}
            errors={errors.medals_is_private}
          >
            <option value="public">Públicas</option>
            <option value="private">Solo yo</option>
          </FormField>

          <FormField
            as="select"
            id="profile-courses-visibility"
            name="enrollments_is_private"
            label="Visibilidad de los cursos destacados"
            hint={
              isPrivate
                ? 'El perfil privado oculta esta sección. Se conserva tu preferencia.'
                : 'Solo se mostrarán los cursos que elijas como destacados.'
            }
            value={coursesIsPrivate ? 'private' : 'public'}
            onChange={(event) =>
              setCoursesIsPrivate(event.target.value === 'private')
            }
            disabled={isPrivate}
            errors={errors.enrollments_is_private}
          >
            <option value="public">Públicos</option>
            <option value="private">Solo yo</option>
          </FormField>
        </fieldset>

        <EnrolledCoursePicker
          selectedIds={featuredIds}
          onChange={setFeaturedIds}
          disabled={saving}
          errors={featuredErrors}
        />

        <div className={styles.actions}>
          <Button
            type="submit"
            disabled={saving || !hasChanges || secondsLeft > 0}
            aria-describedby="profile-save-help"
          >
            {saving ? 'Guardando…' : 'Guardar cambios'}
          </Button>

          <p id="profile-save-help" className={styles.saveHelp}>
            {secondsLeft > 0
              ? `Podrás guardar de nuevo en ${secondsLeft} s.`
              : hasChanges
                ? 'Tienes cambios pendientes.'
                : 'No hay cambios pendientes.'}
          </p>
        </div>
      </fieldset>
    </form>
  )
}
