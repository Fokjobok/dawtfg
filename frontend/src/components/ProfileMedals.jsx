import styles from './ProfileMedals.module.scss'

export default function ProfileMedals({ medals, isOwner }) {
  if (medals.length === 0 && !isOwner) return null

  const groups = new Map()

  for (const medal of medals) {
    const specialtyId = medal.specialty.id

    if (!groups.has(specialtyId)) {
      groups.set(specialtyId, {
        specialty: medal.specialty,
        medals: [],
      })
    }

    groups.get(specialtyId).medals.push(medal)
  }

  const sortedGroups = [...groups.values()].sort((a, b) =>
    a.specialty.name.localeCompare(b.specialty.name),
  )

  return (
    <section className={styles.section} aria-labelledby="profile-medals-title">
      <h2 id="profile-medals-title">Medallas</h2>

      {medals.length === 0 && (
        <p className={styles.empty}>Todavía no has recibido medallas.</p>
      )}

      {sortedGroups.map((group) => (
        <section
          key={group.specialty.id}
          className={styles.group}
          aria-labelledby={`medal-group-${group.specialty.id}`}
        >
          <h3 id={`medal-group-${group.specialty.id}`}>
            {group.specialty.name}
          </h3>

          <ul className={styles.list}>
            {group.medals.map((medal) => (
              <li key={medal.id} className={styles.medal}>
                {medal.image_url && (
                  <img
                    src={medal.image_url}
                    alt=""
                    width={80}
                    height={80}
                    loading="lazy"
                    decoding="async"
                  />
                )}

                <span>{medal.name}</span>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </section>
  )
}
